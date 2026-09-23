"""Upload preserved election, by-election and census PDFs after polling PDFs.

Run under the same private R2 credential wrapper and lock. Original files and
manifests stay local; receipts require a full R2 read-back SHA-256 check.
"""

import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import hashlib
import json
import os
from pathlib import Path
import re
import time
from urllib.parse import urlparse

from sync_polling_pdfs_r2 import append_receipt, verify_remote


ROOT = Path(__file__).resolve().parents[1]
PRIVATE = ROOT / 'application/storage/app/private'
CATEGORIES = ('election-archive', 'election-by-elections', 'census-archive')
HEX24 = re.compile(r'^[a-f0-9]{24}$')
OFFICIAL_HOSTS = {'eci.gov.in', 'www.eci.gov.in', 'old.eci.gov.in', 'censusindia.gov.in', 'www.censusindia.gov.in'}


def _item(private, category, path, digest, url):
    base = (private / category).resolve()
    resolved = path.resolve()
    if not resolved.is_relative_to(base) or not re.fullmatch(r'[a-f0-9]{64}', str(digest)):
        raise ValueError('Unsafe archived PDF path or checksum')
    parsed = urlparse(str(url))
    if not path.is_file() or path.suffix.lower() != '.pdf' or parsed.scheme != 'https' or parsed.hostname not in OFFICIAL_HOSTS:
        raise ValueError('Archived PDF or official source URL is missing')
    relative = resolved.relative_to(private.resolve()).as_posix()
    return {'category': category, 'path': path, 'relative': relative,
            'sha256': digest, 'source_url': url, 'bytes': path.stat().st_size}


def discover(private=PRIVATE):
    """Use preserved manifests, never an unaudited directory-wide upload."""
    items = []
    for category in CATEGORIES[:2]:
        base = private / category
        for manifest_path in sorted(base.glob('*/manifest.json')):
            folder = manifest_path.parent
            if not HEX24.fullmatch(folder.name):
                raise ValueError('Unexpected archive folder identifier')
            manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
            for entry in manifest.get('files', []):
                name = entry.get('file', '')
                if not name.lower().endswith('.pdf'):
                    continue
                if Path(name).name != name or '\\' in name:
                    raise ValueError('Unsafe archived PDF filename')
                items.append(_item(private, category, folder / name, entry['sha256'],
                                   entry.get('source_url') or entry.get('source_page') or manifest.get('url')))
    category = 'census-archive'
    for manifest_path in sorted((private / category).rglob('manifest.json')):
        manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
        relative = manifest.get('path', '')
        if not relative.lower().endswith('.pdf'):
            continue
        items.append(_item(private, category, private / relative, manifest['sha256'], manifest.get('url')))
    unique = {}
    for item in items:
        previous = unique.get(item['relative'])
        if previous and previous['sha256'] != item['sha256']:
            raise ValueError('Conflicting archive manifests for one PDF')
        unique[item['relative']] = item
    return list(unique.values())


def read_receipts(path):
    receipts = {}
    if path.exists():
        with path.open(encoding='utf-8') as stream:
            for line in stream:
                if line.strip():
                    receipt = json.loads(line)
                    receipts[receipt['object_key']] = receipt
    return receipts


def verify_one(item, client, bucket, prefix, endpoint):
    path = item['path']
    with path.open('rb') as stream:
        digest = hashlib.file_digest(stream, 'sha256').hexdigest()
    if digest != item['sha256'] or path.stat().st_size != item['bytes']:
        raise ValueError('Preserved archived PDF changed: ' + item['relative'])
    key = prefix + '/' + item['relative']
    from botocore.exceptions import BotoCoreError, ClientError
    for attempt in range(5):
        try:
            verify_remote(client, bucket, key, path, item['relative'], digest, item['bytes'])
            break
        except (BotoCoreError, ClientError, OSError) as error:
            if isinstance(error, ClientError):
                status = error.response.get('ResponseMetadata', {}).get('HTTPStatusCode')
                if status is not None and status < 500 and status not in (408, 429):
                    raise
            if attempt == 4:
                raise
            time.sleep(min(2 ** attempt, 8))
    return {'source_id': item['relative'], 'category': item['category'], 'sha256': digest,
            'bytes': item['bytes'], 'bucket': bucket, 'object_key': key,
            'source_url': item['source_url'], 'endpoint': endpoint}


def upload(items, receipts_path, client, bucket, prefix, endpoint, workers=4, progress_every=100):
    receipts = read_receipts(receipts_path)
    pending = []
    for item in items:
        key = prefix + '/' + item['relative']
        previous = receipts.get(key)
        if previous:
            if (previous['sha256'] != item['sha256'] or previous['bytes'] != item['bytes']
                    or previous['bucket'] != bucket or previous.get('endpoint') != endpoint):
                raise ValueError('Archived R2 receipt conflicts with current manifest')
            continue
        pending.append(item)
    count = 0
    with ThreadPoolExecutor(max_workers=workers) as pool:
        for offset in range(0, len(pending), workers * 2):
            batch = pending[offset:offset + workers * 2]
            futures = [pool.submit(verify_one, item, client, bucket, prefix, endpoint) for item in batch]
            for future in as_completed(futures):
                record = future.result()
                append_receipt(receipts_path, record)
                count += 1
                if count == 1 or count % progress_every == 0:
                    print(json.dumps({'verified_this_run': count, 'category': record['category']}), flush=True)
    return count


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--workers', type=int, default=4)
    parser.add_argument('--progress-every', type=int, default=100)
    parser.add_argument('--receipts', type=Path, default=ROOT / 'exports/archived-r2-receipts.jsonl')
    args = parser.parse_args()
    if not 1 <= args.workers <= 8 or args.progress_every < 1:
        parser.error('workers must be 1–8 and progress-every positive')
    names = ['POLLMEDIA_R2_ENDPOINT', 'POLLMEDIA_R2_BUCKET', 'POLLMEDIA_R2_ACCESS_KEY_ID', 'POLLMEDIA_R2_SECRET_ACCESS_KEY']
    values = {name: os.environ.get(name) for name in names}
    if not all(values.values()):
        parser.error('R2 environment is incomplete')
    prefix = os.environ.get('POLLMEDIA_R2_PREFIX', 'pollmedia').strip('/')
    if not re.fullmatch(r'[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*', prefix):
        parser.error('R2 prefix is unsafe')
    import boto3
    from botocore.config import Config
    client = boto3.client('s3', endpoint_url=values['POLLMEDIA_R2_ENDPOINT'], region_name='auto',
                          aws_access_key_id=values['POLLMEDIA_R2_ACCESS_KEY_ID'],
                          aws_secret_access_key=values['POLLMEDIA_R2_SECRET_ACCESS_KEY'],
                          config=Config(retries={'max_attempts': 3, 'mode': 'standard'},
                                        max_pool_connections=args.workers * 4))
    from polling_job_lock import extraction_lock
    with extraction_lock(ROOT / 'exports/polling-r2-upload.lock'):
        items = discover()
        count = upload(items, args.receipts, client, values['POLLMEDIA_R2_BUCKET'], prefix,
                       values['POLLMEDIA_R2_ENDPOINT'].rstrip('/'), args.workers, args.progress_every)
    print(json.dumps({'archive_pdfs': len(items), 'verified_this_run': count}))


if __name__ == '__main__':
    main()
