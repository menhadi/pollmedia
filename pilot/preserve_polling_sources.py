"""Create a verified snapshot of the indexed polling-source collection."""
import argparse
import hashlib
import json
from pathlib import Path
import zipfile
from polling_manifest import load_manifest


def preserve(root, output):
    folder = root/'application/storage/app/private/polling-station-sources'
    index_body = (folder/'index.json').read_bytes()
    index = json.loads(index_body)
    if output.exists():
        raise ValueError('Choose a new snapshot filename')
    required = {}
    for source in index['sources']:
        base = folder/source['folder']
        required[base/source['file']] = source['sha256']
        for page in source['pages']:
            required[base/(source['sha256']+'-tables')/page['file']] = page['sha256']
            if page.get('ocr'):
                required[base/(source['sha256']+'-ocr')/page['ocr']['file']] = page['ocr']['sha256']
    metadata = {folder/'index.json':index_body}
    for name in ['catalogue.json','summary.json']:
        if (folder/name).exists():
            metadata[folder/name] = (folder/name).read_bytes()
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
            if item.get('file'):
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
            body = metadata[path] if path in metadata else path.read_bytes()
            digest = hashlib.sha256(body).hexdigest()
            if path in required and digest != required[path]:
                raise ValueError('Source checksum changed: '+str(path))
            name = path.relative_to(root).as_posix()
            archive.writestr(name,body)
            entries.append({'path':name,'sha256':digest,'bytes':len(body)})
        archive.writestr('manifest.json',json.dumps({'scope':'Partial national polling-source snapshot. The indexed coverage note and unresolved discovery/extraction issues remain applicable.', 'files':entries},indent=2))
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
    parser=argparse.ArgumentParser();parser.add_argument('output',type=Path);args=parser.parse_args()
    preserve(Path(__file__).resolve().parents[1],args.output)
