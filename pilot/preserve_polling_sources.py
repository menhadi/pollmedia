"""Create a verified snapshot of the indexed polling-source collection."""
import argparse
import hashlib
import json
from pathlib import Path
import zipfile
from polling_manifest import load_manifest


def preserve(root, output, states=None, data_only=False):
    folder = root/'application/storage/app/private/polling-station-sources'
    index_body = (folder/'index.json').read_bytes()
    index = json.loads(index_body)
    if states:
        selected = set(states)
        available = {state.get('state') for state in index['states']}
        if not selected <= available:
            raise ValueError('Requested state is not in the preserved index')
        index['states'] = [state for state in index['states'] if state.get('state') in selected]
        index['sources'] = [source for source in index['sources'] if source.get('state') in selected]
        index_body = json.dumps(index, ensure_ascii=False).encode('utf-8')
    if output.exists():
        raise ValueError('Choose a new snapshot filename')
    required = {}
    for source in index['sources']:
        base = folder/source['folder']
        if not data_only:
            required[base/source['file']] = source['sha256']
        pages = source.get('pages', [])
        if source.get('page_manifest'):
            reference = source['page_manifest']
            page_path = base/reference['file']
            if page_path.parent != base:
                raise ValueError('Unsafe page manifest path')
            body = page_path.read_bytes()
            if hashlib.sha256(body).hexdigest() != reference['sha256']:
                raise ValueError('Page manifest checksum changed')
            required[page_path] = reference['sha256']
            pages = json.loads(body)['pages']
        for page in pages:
            required[base/(source['sha256']+'-tables')/page['file']] = page['sha256']
            if page.get('ocr'):
                required[base/(source['sha256']+'-ocr')/page['ocr']['file']] = page['ocr']['sha256']
        ocr_index = base/(source['sha256']+'-ocr')/'index.json'
        if ocr_index.exists():
            ocr_body = ocr_index.read_bytes()
            required[ocr_index] = hashlib.sha256(ocr_body).hexdigest()
            for page in json.loads(ocr_body).get('pages', []):
                if Path(page['file']).name != page['file']:
                    raise ValueError('Unsafe OCR page path')
                required[ocr_index.parent/page['file']] = page['sha256']
    metadata = {folder/'index.json':index_body}
    for name in ['catalogue.json','summary.json']:
        if (folder/name).exists():
            if states and name == 'summary.json':
                continue
            body = (folder/name).read_bytes()
            if states and name == 'catalogue.json':
                catalogue = json.loads(body)
                catalogue['entries'] = [entry for entry in catalogue['entries'] if entry['state'] in selected]
                body = json.dumps(catalogue, ensure_ascii=False).encode('utf-8')
            metadata[folder/name] = body
    for state in index['states']:
        path = folder/state['id']/'manifest.json'
        if not path.exists():
            continue
        body = path.read_bytes()
        record = load_manifest(path)
        metadata[path] = body
        for supplement in path.parent.glob('*-supplement.json'):
            metadata[supplement] = supplement.read_bytes()
        for item in record.get('pages', [])+record.get('api_responses', [])+record.get('documents', []):
            if item.get('file') and not data_only:
                required[path.parent/item['file']] = item['sha256']
    temporary = output.with_suffix('.partial')
    if temporary.exists():
        raise ValueError('An incomplete snapshot already exists')
    output.parent.mkdir(parents=True,exist_ok=True)
    entries = []
    with zipfile.ZipFile(temporary,'x',zipfile.ZIP_DEFLATED,compresslevel=1,allowZip64=True) as archive:
        for path in sorted(set(required)|set(metadata)):
            if not path.resolve().is_relative_to(folder.resolve()):
                raise ValueError('Unsafe snapshot source path')
            if path in metadata:
                body = metadata[path]
                digest = hashlib.sha256(body).hexdigest()
                size = len(body)
            else:
                with path.open('rb') as stream:
                    digest = hashlib.file_digest(stream, 'sha256').hexdigest()
                size = path.stat().st_size
            if path in required and digest != required[path]:
                raise ValueError('Source checksum changed: '+str(path))
            name = path.relative_to(root).as_posix()
            if path in metadata:
                archive.writestr(name,body)
            else:
                archive.write(path,name)
            entries.append({'path':name,'sha256':digest,'bytes':size})
        scope = ('State polling-source snapshot: '+', '.join(sorted(selected)) if states else 'Partial national polling-source snapshot')
        if data_only:
            scope += '. Database import data only; original documents are held separately in R2'
        archive.writestr('manifest.json',json.dumps({'scope':scope+'. The indexed coverage note and unresolved discovery/extraction issues remain applicable.', 'files':entries},indent=2))
    with zipfile.ZipFile(temporary) as archive:
        for item in entries:
            with archive.open(item['path']) as stream:
                if hashlib.file_digest(stream,'sha256').hexdigest() != item['sha256']:
                    raise ValueError('ZIP verification failed')
    temporary.replace(output)
    with output.open('rb') as stream:
        digest = hashlib.file_digest(stream,'sha256').hexdigest()
    output.with_suffix('.sha256').write_bytes((digest+'  '+output.name+'\n').encode('ascii'))
    print(json.dumps({'files':len(entries),'bytes':output.stat().st_size,'sha256':digest}))


if __name__ == '__main__':
    parser=argparse.ArgumentParser();parser.add_argument('output',type=Path);parser.add_argument('--state',action='append');parser.add_argument('--data-only',action='store_true');args=parser.parse_args()
    preserve(Path(__file__).resolve().parents[1],args.output,args.state,args.data_only)
