"""Remap preserved PDF cells without downloading or re-reading the PDF geometry.

Documents already on the current adapter are skipped, as are documents in which every page
already mapped: a mapping upgrade can only add rows where a page failed to map.
"""
import hashlib
import argparse
import json
from pathlib import Path
from extract_polling_sources import ADAPTER, CARRIED_HEADER_NOTE, MAPPING_NOTE, table_header, map_rows, continues_table


def remap(root, state=None):
    folders = None
    if state:
        catalogue = json.loads((root/'catalogue.json').read_text(encoding='utf-8'))
        folders = {entry['id'] for entry in catalogue['entries'] if entry['state'] == state}
        if not folders:
            raise ValueError('State is not in the preserved source directory: '+state)
    changed = rows = 0
    for path in root.glob('*/*-tables/index.json'):
        if folders is not None and path.parent.parent.name not in folders:
            continue
        original = path.read_bytes()
        document = json.loads(original)
        if document.get('adapter') == ADAPTER:
            continue
        if not any(MAPPING_NOTE in page['notes'] for page in document['pages']):
            continue
        header_context = None
        for page in document['pages']:
            source = path.parent/page['file']
            body = source.read_bytes()
            if hashlib.sha256(body).hexdigest() != page['sha256']:
                raise ValueError('Preserved page checksum changed: '+str(source))
            data = json.loads(body)
            mapped = []
            carried = False
            for table in data['tables']:
                context = table_header(table['cells'])
                if context is not None:
                    header_context = context
                    found = map_rows(table['cells'], context, context['header']+2)
                elif header_context is not None and continues_table(table['cells'], header_context):
                    carried = True
                    found = map_rows(table['cells'], header_context, 0, CARRIED_HEADER_NOTE)
                else:
                    found = []
                mapped.extend(row | {'table':table['number']} for row in found)
            if len(mapped) < len(data['polling_rows']):
                raise ValueError('Adapter regressed existing polling rows: '+str(source))
            data['polling_rows'] = mapped
            data['notes'] = [n for n in data['notes'] if n not in [MAPPING_NOTE, CARRIED_HEADER_NOTE]]
            if mapped:
                data['notes'] = [n for n in data['notes'] if n != MAPPING_NOTE]
            elif data['tables']:
                data['notes'].append(MAPPING_NOTE)
            if carried:
                data['notes'].append(CARRIED_HEADER_NOTE)
            data['adapter'] = ADAPTER
            body = json.dumps(data,ensure_ascii=False).encode('utf-8')
            digest = hashlib.sha256(body).hexdigest()
            filename = str(page['page'])+'-'+digest[:16]+'.json'
            (path.parent/filename).write_bytes(body)
            page.update(file=filename,sha256=digest,polling_rows=len(mapped),flagged_rows=sum(bool(r['notes']) for r in mapped),notes=data['notes'])
        document.update(adapter=ADAPTER,polling_rows=sum(p['polling_rows'] for p in document['pages']))
        (path.parent/('index-'+hashlib.sha256(original).hexdigest()+'.json')).write_bytes(original)
        temporary = path.with_suffix('.tmp')
        temporary.write_text(json.dumps(document,ensure_ascii=False,indent=2),encoding='utf-8')
        temporary.replace(path)
        changed += 1; rows += document['polling_rows']
    print(json.dumps({'remapped_documents':changed,'polling_rows_in_remapped_documents':rows}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--state')
    args = parser.parse_args()
    remap(Path(__file__).resolve().parents[1]/'application/storage/app/private/polling-station-sources', args.state)
