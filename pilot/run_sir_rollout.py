"""Resume a source-verified nationwide SIR manifest one official part at a time.

Each entry contains metadata (relative JSON path) and either a local PDF or a
published government download_url. Extraction is cached by PDF checksum.
Use --publish-local to add each validated part to the local Laravel database.
"""
import argparse
import hashlib
import json
import subprocess
from pathlib import Path
from urllib.parse import urlparse
import requests

ROOT = Path(__file__).resolve().parent.parent


def official(url):
    host = (urlparse(url).hostname or '').lower()
    return urlparse(url).scheme == 'https' and (host.endswith('.gov.in') or host.endswith('.nic.in'))


def download(url, target, expected):
    if not official(url):
        raise ValueError('Downloads require a published HTTPS government PDF URL')
    target.parent.mkdir(parents=True, exist_ok=True)
    temporary = target.with_suffix('.partial')
    try:
        # Follow only government publication redirects, never authentication or CAPTCHA workarounds.
        for _ in range(6):
            response = requests.get(url, stream=True, timeout=(20, 90), allow_redirects=False)
            if response.is_redirect:
                from urllib.parse import urljoin
                next_url = urljoin(url, response.headers['Location'])
                response.close()
                if not official(next_url):
                    raise ValueError('Non-government redirect needs source verification')
                url = next_url
                continue
            response.raise_for_status()
            size = 0
            with temporary.open('wb') as stream:
                for chunk in response.iter_content(1024*1024):
                    size += len(chunk)
                    if size > 100000000:
                        raise ValueError('PDF exceeds 100 MB')
                    stream.write(chunk)
            response.close()
            break
        else:
            raise ValueError('Too many publication redirects')
        if temporary.read_bytes()[:5] != b'%PDF-' or hashlib.sha256(temporary.read_bytes()).hexdigest() != expected:
            raise ValueError('Downloaded PDF checksum or format mismatch')
        temporary.replace(target)
    finally:
        temporary.unlink(missing_ok=True)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('manifest')
    parser.add_argument('--publish-local', action='store_true')
    parser.add_argument('--workers', type=int, default=3)
    args = parser.parse_args()
    manifest = Path(args.manifest).resolve()
    entries = json.loads(manifest.read_text(encoding='utf-8'))
    base = manifest.parent
    checkpoint_path = manifest.with_suffix('.checkpoint.json')
    checkpoint = json.loads(checkpoint_path.read_text(encoding='utf-8')) if checkpoint_path.exists() else {}
    failures = 0
    for entry in entries:
        meta_path = (base/entry['metadata']).resolve()
        meta = json.loads(meta_path.read_text(encoding='utf-8'))
        sha = meta['pdf_sha256']
        pdf = (base/entry['pdf']).resolve()
        output = ROOT/'exports'/'sir'/(sha+'.records.json')
        try:
            if not pdf.exists():
                download(entry['download_url'], pdf, sha)
            if hashlib.sha256(pdf.read_bytes()).hexdigest() != sha:
                raise ValueError('Local PDF differs from verified source metadata')
            subprocess.run(['python', str(ROOT/'pilot'/'extract_sir_voter_roll.py'), str(pdf), '--metadata='+str(meta_path), '--output='+str(output), '--workers='+str(args.workers)], check=True)
            json_sha = hashlib.sha256(output.read_bytes()).hexdigest()
            status = 'prepared'
            if args.publish_local:
                subprocess.run(['php', 'artisan', 'sir:import-records', str(output), '--sha256='+json_sha, '--pdf='+str(pdf)], cwd=ROOT/'application', check=True)
                status = 'imported_local'
            checkpoint[sha] = dict(status=status, json_sha256=json_sha, records_file=str(output), pdf_file=str(pdf))
        except Exception as error:
            failures += 1
            checkpoint[sha] = dict(status='needs_review', error=str(error))
        temporary = checkpoint_path.with_suffix('.tmp')
        temporary.write_text(json.dumps(checkpoint, ensure_ascii=False, indent=2), encoding='utf-8')
        temporary.replace(checkpoint_path)
        print(json.dumps({sha:checkpoint[sha]}, ensure_ascii=True), flush=True)
    return 1 if failures else 0


if __name__ == '__main__':
    raise SystemExit(main())
