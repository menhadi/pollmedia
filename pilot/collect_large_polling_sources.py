"""Recover oversized official PDFs using disk streaming rather than loading them into RAM."""
import hashlib
import json
from pathlib import Path
import shutil
import subprocess
from collect_polling_sources import official


def download(url, folder):
    if not official(url):
        raise ValueError('Non-official source URL')
    if shutil.disk_usage(folder).free < 2_000_000_000:
        raise ValueError('Archive disk reserve reached')
    temporary = folder/'large-document.part'
    curl = shutil.which('curl.exe') or shutil.which('curl')
    try:
        result = subprocess.run([curl, '--silent', '--show-error', '--fail', '--location', '--max-redirs', '5',
            '--connect-timeout', '20', '--max-time', '900', '--max-filesize', '536870912',
            '--write-out', '%{url_effective}', '--output', str(temporary), url], capture_output=True)
        if result.returncode:
            raise ValueError('Large source download failed; curl '+str(result.returncode))
        final = result.stdout.decode().strip()
        if not official(final):
            raise ValueError('Source redirected outside official government domains')
        with temporary.open('rb') as stream:
            if stream.read(5) != b'%PDF-':
                raise ValueError('Large source is not a PDF')
            stream.seek(0)
            digest = hashlib.file_digest(stream, 'sha256').hexdigest()
        size = temporary.stat().st_size
        target = folder/(digest+'.pdf')
        if target.exists():
            with target.open('rb') as stream:
                if hashlib.file_digest(stream, 'sha256').hexdigest() != digest:
                    raise ValueError('Previously preserved source checksum changed')
        else:
            temporary.replace(target)
        return {'file': target.name, 'sha256': digest, 'bytes': size, 'final_url': final, 'status': 'archived_requires_extraction'}
    finally:
        temporary.unlink(missing_ok=True)


def collect(root):
    completed = 0
    for manifest_path in root.glob('*/manifest.json'):
        manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
        jobs = [d for d in manifest['documents'] if d.get('status') == 'download_failed' and d.get('error') == 'Official download failed; curl 63']
        if not jobs:
            continue
        folder = manifest_path.parent
        path = folder/'large-files-supplement.json'
        saved = json.loads(path.read_text(encoding='utf-8')) if path.exists() else {'documents': []}
        documents = {d['url']: d for d in saved['documents']}
        for item in jobs:
            existing = documents.get(item['url'], {})
            if existing.get('file') and (folder/existing['file']).exists():
                with (folder/existing['file']).open('rb') as stream:
                    if hashlib.file_digest(stream, 'sha256').hexdigest() == existing['sha256']:
                        continue
            record = {k: v for k, v in item.items() if k not in ['error', 'file', 'sha256', 'bytes']}
            try:
                record.update(download(item['url'], folder))
                completed += 1
                print(manifest['state']+': preserved '+str(record['bytes'])+' bytes', flush=True)
            except Exception as error:
                record.update(status='download_failed', error=str(error))
                print(manifest['state']+': '+str(error), flush=True)
            documents[item['url']] = record
            temporary = path.with_suffix('.tmp')
            temporary.write_text(json.dumps({'documents': list(documents.values()), 'errors': [],
                'scope_note': 'Oversized official source recovery. Files larger than 512 MiB and unavailable sources remain gaps.'}, ensure_ascii=False, indent=2), encoding='utf-8')
            temporary.replace(path)
    print(json.dumps({'newly_preserved_large_sources': completed}), flush=True)


if __name__ == '__main__':
    collect(Path(__file__).resolve().parents[1]/'application/storage/app/private/polling-station-sources')
