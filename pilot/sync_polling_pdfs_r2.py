"""Upload preserved polling-source PDFs directly to a private R2 bucket.

Credentials are read only from the process environment. This does not delete originals.
"""

import argparse
import hashlib
import json
import os
from pathlib import Path
import re


ROOT = Path(__file__).resolve().parents[1]
SOURCE_ROOT = ROOT / 'application/storage/app/private/polling-station-sources'
HEX24 = re.compile(r'^[a-f0-9]{24}$')
PDF_NAME = re.compile(r'^[a-f0-9]{64}\.pdf$')


def sha256(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def read_receipts(path):
    if not path.exists():
        return {}
    receipts = {}
    with path.open(encoding='utf-8') as stream:
        for line in stream:
            record = json.loads(line)
            receipts[record['source_id']] = record
    return receipts


def upload(index, folder, receipts_path, client, bucket, prefix, states=None, limit=None, endpoint=None):
    receipts = read_receipts(receipts_path)
    count = 0
    for source in index['sources']:
        if states and source['state'] not in states:
            continue
        if not HEX24.fullmatch(source['id']) or not HEX24.fullmatch(source['folder']):
            raise ValueError('Unexpected source identifier')
        if not PDF_NAME.fullmatch(source['file']):
            continue
        expected = source['sha256']
        if source['file'] != expected + '.pdf':
            raise ValueError('PDF name and digest disagree')
        key = f"{prefix}/polling-station-sources/{source['folder']}/{source['file']}"
        previous = receipts.get(source['id'])
        if previous:
            if previous['sha256'] != expected or previous['bucket'] != bucket or previous['object_key'] != key or previous.get('endpoint') != endpoint:
                raise ValueError('Receipt conflicts with current source')
            continue
        path = folder / source['folder'] / source['file']
        if not path.is_file() or sha256(path) != expected:
            raise ValueError('Preserved PDF is missing or changed: ' + source['id'])
        size = path.stat().st_size
        try:
            head = client.head_object(Bucket=bucket, Key=key)
        except Exception as error:
            from botocore.exceptions import ClientError
            if not isinstance(error, ClientError) or error.response.get('Error', {}).get('Code') not in ('404', 'NoSuchKey', 'NotFound'):
                raise
            head = None
        if head:
            metadata = head.get('Metadata', {})
            if head['ContentLength'] != size or metadata.get('sha256') != expected:
                raise ValueError('An R2 object already exists with different metadata: ' + key)
        else:
            client.upload_file(str(path), bucket, key, ExtraArgs={
                'ContentType': 'application/pdf', 'Metadata': {'sha256': expected, 'source-id': source['id']},
            })
            head = client.head_object(Bucket=bucket, Key=key)
            if head['ContentLength'] != size or head.get('Metadata', {}).get('sha256') != expected:
                raise ValueError('R2 upload metadata verification failed: ' + key)
        remote_hash = hashlib.sha256()
        body = client.get_object(Bucket=bucket, Key=key)['Body']
        try:
            for chunk in body.iter_chunks(chunk_size=1024 * 1024):
                remote_hash.update(chunk)
        finally:
            body.close()
        if remote_hash.hexdigest() != expected:
            raise ValueError('R2 download checksum differs: ' + key)
        record = {'source_id': source['id'], 'state': source['state'], 'sha256': expected,
                  'bytes': size, 'bucket': bucket, 'object_key': key,
                  'source_url': source['source_url'], 'discovered_on': source['discovered_on'], 'endpoint': endpoint}
        receipts_path.parent.mkdir(parents=True, exist_ok=True)
        with receipts_path.open('a', encoding='utf-8', newline='\n') as stream:
            stream.write(json.dumps(record, ensure_ascii=False) + '\n')
            stream.flush()
            os.fsync(stream.fileno())
        receipts[source['id']] = record
        count += 1
        print(json.dumps({'uploaded_or_verified': count, 'source_id': source['id'], 'bytes': size}), flush=True)
        if limit and count >= limit:
            break
    return count


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--state', action='append')
    parser.add_argument('--limit', type=int)
    parser.add_argument('--receipts', type=Path, default=ROOT / 'exports/polling-r2-receipts.jsonl')
    args = parser.parse_args()
    if args.limit is not None and args.limit < 1:
        parser.error('--limit must be positive')
    names = ['POLLMEDIA_R2_ENDPOINT', 'POLLMEDIA_R2_BUCKET', 'POLLMEDIA_R2_ACCESS_KEY_ID', 'POLLMEDIA_R2_SECRET_ACCESS_KEY']
    values = {name: os.environ.get(name) for name in names}
    if not all(values.values()):
        parser.error('Configure ' + ', '.join(names) + ' in the process environment')
    prefix = os.environ.get('POLLMEDIA_R2_PREFIX', 'pollmedia').strip('/')
    if not re.fullmatch(r'[A-Za-z0-9_-]+(?:/[A-Za-z0-9_-]+)*', prefix):
        parser.error('POLLMEDIA_R2_PREFIX must contain safe path segments')
    import boto3
    from botocore.config import Config
    client = boto3.client('s3', endpoint_url=values['POLLMEDIA_R2_ENDPOINT'], region_name='auto',
                          aws_access_key_id=values['POLLMEDIA_R2_ACCESS_KEY_ID'],
                          aws_secret_access_key=values['POLLMEDIA_R2_SECRET_ACCESS_KEY'],
                          config=Config(retries={'max_attempts': 3, 'mode': 'standard'}))
    index = json.loads((SOURCE_ROOT / 'index.json').read_text(encoding='utf-8'))
    count = upload(index, SOURCE_ROOT, args.receipts, client, values['POLLMEDIA_R2_BUCKET'], prefix,
                   set(args.state or []), args.limit, values['POLLMEDIA_R2_ENDPOINT'].rstrip('/'))
    print(json.dumps({'verified_this_run': count, 'receipt_file': str(args.receipts)}))


if __name__ == '__main__':
    main()
