"""Bundle collected by-election files, tables and coverage without changing source files."""
import argparse
import hashlib
import json
from pathlib import Path
import zipfile


def preserve(root, output):
    folder = root/'application/storage/app/private/election-by-elections'
    if output.exists():
        raise ValueError('Choose a new snapshot filename')
    for path in folder.glob('*/manifest.json'):
        manifest = json.loads(path.read_text(encoding='utf-8'))
        for item in manifest['files']+manifest.get('extractions', []):
            source = path.parent/item['file']
            if source.parent != path.parent or hashlib.sha256(source.read_bytes()).hexdigest() != item['sha256']:
                raise ValueError('File checksum changed: '+str(source))
    files = []
    with zipfile.ZipFile(output, 'x', zipfile.ZIP_DEFLATED, compresslevel=1) as archive:
        for path in sorted(folder.rglob('*')):
            if not path.is_file() or path.suffix in ['.part', '.tmp']:
                continue
            name = path.relative_to(root).as_posix()
            body = path.read_bytes()
            archive.writestr(name, body)
            files.append({'path': name, 'sha256': hashlib.sha256(body).hexdigest(), 'bytes': len(body)})
        archive.writestr('manifest.json', json.dumps({'scope': 'By-election source collection and raw tables; structured validation and missing source recovery remain pending.', 'files': files}, indent=2))
    with zipfile.ZipFile(output) as archive:
        for item in files:
            if hashlib.sha256(archive.read(item['path'])).hexdigest() != item['sha256']:
                raise ValueError('Bundle integrity check failed')
    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    output.with_suffix('.sha256').write_bytes((digest+'  '+output.name+'\n').encode('ascii'))
    print(json.dumps({'sha256': digest, 'files': len(files), 'bytes': output.stat().st_size}))


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    preserve(Path(__file__).resolve().parents[1], args.output)
