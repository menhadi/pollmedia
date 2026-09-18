"""Create a portable, verified snapshot of the collected Assembly source archive."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import zipfile


def preserve(root, output):
    if output.exists() or output.with_suffix('.partial').exists():
        raise ValueError('Choose a new archive filename; snapshots are never overwritten')
    output.parent.mkdir(parents=True, exist_ok=True)
    catalogue = root/'application/database/fixtures/eci-assembly-national.json'
    data = json.loads(catalogue.read_text(encoding='utf-8'))
    files = {catalogue, root/'application/database/fixtures/eci-assembly-static.json', root/'pilot/raw/eci-statistical-catalogue.json', root/'pilot/raw/eci-report-links.js'}
    for entry in data['entries']:
        folder = root/'application/storage/app/private/election-archive'/hashlib.sha256(entry['url'].encode()).hexdigest()[:24]
        manifest = json.loads((folder/'manifest.json').read_text(encoding='utf-8'))
        if manifest['url'] != entry['url'] or manifest['status'] != 'collected':
            raise ValueError('Incomplete source collection: ' + entry['url'])
        for item in manifest['files']:
            source = folder/item['file']
            if source.parent != folder or hashlib.sha256(source.read_bytes()).hexdigest() != item['sha256']:
                raise ValueError('Source checksum differs: ' + str(source))
        files.update(p for p in folder.iterdir() if p.is_file() and p.suffix not in ['.part','.tmp'])
    entries = []
    temporary = output.with_suffix('.partial')
    with zipfile.ZipFile(temporary,'x',compression=zipfile.ZIP_DEFLATED,compresslevel=1,allowZip64=True) as archive:
        for path in sorted(files):
            before = path.stat(); digest=hashlib.sha256(); name=path.relative_to(root).as_posix()
            with path.open('rb') as source, archive.open(name,'w',force_zip64=True) as target:
                while block:=source.read(1024*1024): digest.update(block); target.write(block)
            after = path.stat()
            if (before.st_size,before.st_mtime_ns)!=(after.st_size,after.st_mtime_ns): raise ValueError('Source changed during preservation')
            entries.append({'path':name,'bytes':before.st_size,'sha256':digest.hexdigest()})
        manifest={'created_at':datetime.now(timezone.utc).isoformat(),'scope':'Assembly original sources and extracted snapshots, including notes and official links; not a complete application database backup','catalogue_editions':len(data['entries']),'files':entries}
        archive.writestr('manifest.json',json.dumps(manifest,indent=2))
    temporary.rename(output)
    with zipfile.ZipFile(output) as archive:
        for entry in entries:
            digest=hashlib.sha256()
            with archive.open(entry['path']) as source:
                while block:=source.read(1024*1024): digest.update(block)
            if digest.hexdigest()!=entry['sha256']: raise ValueError('Archive verification failed')
    digest=hashlib.sha256()
    with output.open('rb') as source:
        while block:=source.read(1024*1024):digest.update(block)
    output.with_suffix('.sha256').write_text(digest.hexdigest()+'  '+output.name+'\n',encoding='ascii')
    output.with_suffix('.manifest.json').write_text(json.dumps(manifest,indent=2),encoding='utf-8')
    print(json.dumps({'archive':str(output),'bytes':output.stat().st_size,'verified_files':len(entries),'editions':len(data['entries'])}),flush=True)


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--output',type=Path,required=True);args=parser.parse_args()
    preserve(Path(__file__).resolve().parents[1],args.output.resolve())
