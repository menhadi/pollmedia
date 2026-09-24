"""Preserve archived HTML, workbooks and ZIP originals in private R2.

The source files stay local. Each receipt follows a full R2 read-back checksum.
"""

import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import hashlib
import json
import os
from pathlib import Path
import re
import time
from urllib.parse import urljoin, urlparse

from bs4 import BeautifulSoup

from sync_polling_pdfs_r2 import append_receipt, verify_remote


ROOT = Path(__file__).resolve().parents[1]
PRIVATE = ROOT / 'application/storage/app/private'
CATEGORIES = ('election-archive', 'election-by-elections')
EXTENSIONS = {'.html', '.xls', '.xlsx', '.zip'}
CONTENT_TYPES = {
    '.html': 'text/html', '.xls': 'application/vnd.ms-excel',
    '.xlsx': 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    '.zip': 'application/zip',
}
OFFICIAL_HOSTS = {'eci.gov.in', 'www.eci.gov.in', 'old.eci.gov.in'}
HEX24 = re.compile(r'^[a-f0-9]{24}$')
HEX64 = re.compile(r'^[a-f0-9]{64}$')


def digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def official(url):
    parsed = urlparse(str(url))
    return parsed.scheme == 'https' and parsed.hostname in OFFICIAL_HOSTS


def page_urls(folder, manifest):
    """Map preserved page IDs to unique official report pages, without guessing."""
    pages = {}
    for entry in manifest.get('files', []):
        url = entry.get('source_page')
        if official(url):
            pages.setdefault(hashlib.sha256(url.encode()).hexdigest()[:24], set()).add(url)
    for html in folder.glob('category-*.html'):
        soup = BeautifulSoup(html.read_bytes(), 'html.parser')
        for link in soup.select('a[href]'):
            url = urljoin(manifest['url'], link['href'])
            if official(url) and '/files/file/' in urlparse(url).path:
                pages.setdefault(hashlib.sha256(url.encode()).hexdigest()[:24], set()).add(url)
    return {key: next(iter(urls)) for key, urls in pages.items() if len(urls) == 1}


def discover(private=PRIVATE):
    items = []
    for category in CATEGORIES:
        base = private / category
        for manifest_path in sorted(base.glob('*/manifest.json')):
            folder = manifest_path.parent
            if not HEX24.fullmatch(folder.name):
                raise ValueError('Unexpected archive folder identifier')
            manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
            collection_url = manifest.get('url')
            if not official(collection_url):
                raise ValueError('Archive collection lacks an official HTTPS URL')
            listed = {entry.get('file'): entry for entry in manifest.get('files', [])
                      if isinstance(entry, dict) and isinstance(entry.get('file'), str)}
            pages = page_urls(folder, manifest)
            for path in sorted(folder.iterdir()):
                if not path.is_file() or path.suffix.lower() not in EXTENSIONS:
                    continue
                relative = path.relative_to(private).as_posix()
                entry = listed.get(path.name)
                if entry:
                    expected = entry.get('sha256')
                    if (not isinstance(expected, str) or not HEX64.fullmatch(expected)
                            or entry.get('bytes') != path.stat().st_size):
                        raise ValueError('Listed original metadata differs: ' + relative)
                    url = entry.get('source_url') or entry.get('source_page') or collection_url
                    scope = 'download' if entry.get('source_url') else 'page' if entry.get('source_page') else 'collection'
                else:
                    expected = digest(path)
                    match = re.match(r'^(?:page-|response-|category-)?([a-f0-9]{24})(?:[-.])', path.name)
                    page = pages.get(match.group(1)) if match else None
                    if path.name.startswith('page-') and page:
                        url, scope = page + '?do=download', 'page'
                    elif page:
                        url, scope = page, 'page'
                    else:
                        url, scope = collection_url, 'collection'
                if not official(url):
                    raise ValueError('Archived original lacks an official HTTPS URL: ' + relative)
                items.append({'relative': relative, 'category': category, 'path': path,
                              'sha256': expected, 'bytes': path.stat().st_size,
                              'source_url': url, 'url_scope': scope})
    return items


def read_receipts(path):
    receipts = {}
    if path.exists():
        with path.open(encoding='utf-8') as stream:
            for line in stream:
                if line.strip():
                    receipt = json.loads(line)
                    key = receipt['source_id']
                    if key in receipts:
                        raise ValueError('Duplicate archived original receipt')
                    receipts[key] = receipt
    return receipts


def verify_one(item, client, bucket, prefix, endpoint):
    path = item['path']
    if path.stat().st_size != item['bytes'] or digest(path) != item['sha256']:
        raise ValueError('Preserved archived original changed: ' + item['relative'])
    key = prefix + '/archive-source-originals/' + item['relative']
    from botocore.exceptions import BotoCoreError, ClientError
    for attempt in range(5):
        try:
            verify_remote(client, bucket, key, path, item['relative'], item['sha256'],
                          item['bytes'], CONTENT_TYPES[path.suffix.lower()])
            break
        except (BotoCoreError, ClientError, OSError) as error:
            if isinstance(error, ClientError):
                status = error.response.get('ResponseMetadata', {}).get('HTTPStatusCode')
                if status is not None and status < 500 and status not in (408, 429):
                    raise
            if attempt == 4:
                raise
            time.sleep(min(2 ** attempt, 8))
    return {'source_id': item['relative'], 'category': item['category'],
            'sha256': item['sha256'], 'bytes': item['bytes'], 'bucket': bucket,
            'object_key': key, 'source_url': item['source_url'],
            'url_scope': item['url_scope'], 'endpoint': endpoint}


def upload(items, receipts_path, client, bucket, prefix, endpoint, workers=3, progress_every=100):
    receipts = read_receipts(receipts_path)
    pending = []
    for item in items:
        previous = receipts.get(item['relative'])
        if previous:
            expected_key = prefix + '/archive-source-originals/' + item['relative']
            if (previous['sha256'] != item['sha256'] or previous['bytes'] != item['bytes']
                    or previous['bucket'] != bucket or previous['object_key'] != expected_key
                    or previous.get('endpoint') != endpoint
                    or previous['source_url'] != item['source_url'] or previous['url_scope'] != item['url_scope']):
                raise ValueError('Archived original receipt conflicts with preserved source')
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
    parser.add_argument('--workers', type=int, default=3)
    parser.add_argument('--progress-every', type=int, default=100)
    parser.add_argument('--receipts', type=Path, default=ROOT / 'exports/archive-originals-r2-receipts.jsonl')
    args = parser.parse_args()
    if not 1 <= args.workers <= 4 or args.progress_every < 1:
        parser.error('workers must be 1–4 and progress-every positive')
    names = ['POLLMEDIA_R2_ENDPOINT', 'POLLMEDIA_R2_BUCKET',
             'POLLMEDIA_R2_ACCESS_KEY_ID', 'POLLMEDIA_R2_SECRET_ACCESS_KEY']
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
    print(json.dumps({'archived_originals': len(items), 'verified_this_run': count}))


if __name__ == '__main__':
    main()
