"""Remap preserved PDF cells without downloading or re-reading the PDF geometry."""
import hashlib
import json
from pathlib import Path
from extract_polling_sources import map_table


def remap(root):
    changed = rows = 0
    for path in root.glob('*/*-tables/index.json'):
        original = path.read_bytes()
        document = json.loads(original)
        if document.get('adapter') == 'form20-grid-v2':
            continue
        for page in document['pages']:
            source = path.parent/page['file']
            body = source.read_bytes()
            if hashlib.sha256(body).hexdigest() != page['sha256']:
                raise ValueError('Preserved page checksum changed: '+str(source))
            data = json.loads(body)
            mapped = [row | {'table':table['number']} for table in data['tables'] for row in map_table(table['cells'])]
            if len(mapped) < len(data['polling_rows']):
                raise ValueError('Adapter regressed existing polling rows: '+str(source))
            data['polling_rows'] = mapped
            if mapped:
                data['notes'] = [n for n in data['notes'] if n != 'Source tables extracted; polling-row layout still requires mapping.']
            data['adapter'] = 'form20-grid-v2'
            body = json.dumps(data,ensure_ascii=False).encode('utf-8')
            digest = hashlib.sha256(body).hexdigest()
            filename = str(page['page'])+'-'+digest[:16]+'.json'
            (path.parent/filename).write_bytes(body)
            page.update(file=filename,sha256=digest,polling_rows=len(mapped),flagged_rows=sum(bool(r['notes']) for r in mapped),notes=data['notes'])
        document.update(adapter='form20-grid-v2',polling_rows=sum(p['polling_rows'] for p in document['pages']))
        (path.parent/('index-'+hashlib.sha256(original).hexdigest()+'.json')).write_bytes(original)
        temporary = path.with_suffix('.tmp')
        temporary.write_text(json.dumps(document,ensure_ascii=False,indent=2),encoding='utf-8')
        temporary.replace(path)
        changed += 1; rows += document['polling_rows']
    print(json.dumps({'remapped_documents':changed,'polling_rows_in_remapped_documents':rows}))


if __name__ == '__main__':
    remap(Path(__file__).resolve().parents[1]/'application/storage/app/private/polling-station-sources')
