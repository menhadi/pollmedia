"""Feed isolated text/OCR queues from explicit official Census catalogue URLs.

Called only while the primary worker lock is held. No live database access.
Download errors retain retry timestamps and do not stop other sources.
"""
from datetime import datetime, timezone
from html.parser import HTMLParser
import hashlib
import json
from pathlib import Path
import ssl
import time
import zipfile
from urllib.parse import urljoin, urlsplit
from urllib.request import urlopen

from census_server_worker import digest, resources_ok


def official(url):
    p = urlsplit(url)
    return p.scheme == 'https' and p.netloc == 'censusindia.gov.in' and p.path.startswith('/nada/')


class Links(HTMLParser):
    def __init__(self, base):
        super().__init__(); self.base = base; self.urls = set()

    def handle_starttag(self, tag, attrs):
        if tag == 'a':
            url = urljoin(self.base, dict(attrs).get('href', ''))
            if official(url) and '/download/' in url and urlsplit(url).path.lower().endswith('.pdf'):
                self.urls.add(url)


def save(path, value):
    path.parent.mkdir(parents=True, exist_ok=True)
    tmp = path.with_suffix('.tmp')
    tmp.write_text(json.dumps(value, indent=2), encoding='utf-8'); tmp.replace(path)


def fetch(url, path, context, limit):
    if not official(url):
        raise ValueError('Non-official source URL')
    tmp = path.with_suffix('.partial')
    try:
        with urlopen(url, context=context, timeout=45) as response, tmp.open('wb') as target:
            if not official(response.url):
                raise ValueError('Non-official redirect')
            size = 0
            while chunk := response.read(65536):
                size += len(chunk)
                if size > limit:
                    raise ValueError('Download exceeds bounded size')
                target.write(chunk)
        tmp.replace(path)
    finally:
        tmp.unlink(missing_ok=True)


def feed(root, download_limit=4):
    root = Path(root)
    state_path = root / 'feeder-status.json'
    state = json.loads(state_path.read_text()) if state_path.exists() else {'catalogues': {}, 'downloads': {}}
    context = ssl.create_default_context()
    if (root / 'emsign-intermediate.pem').exists():
        context.load_verify_locations(cafile=str(root / 'emsign-intermediate.pem'))
    evidence = root / 'source-evidence'; evidence.mkdir(exist_ok=True)
    (root / 'packages').mkdir(exist_ok=True)
    # The OCR root has its own database, status and lock. Only evidence/package paths are shared.
    ocr = root / 'ocr-worker'
    if not (ocr / 'queue').is_dir():
        raise ValueError('Separate OCR worker must be configured first')
    remaining = download_limit
    workbook_registry = root / 'workbook-sources.json'
    for item in json.loads(workbook_registry.read_text()) if workbook_registry.exists() else []:
        records = state.setdefault('workbook_downloads', {})
        url = item['source_url']; record = records.setdefault(url, {})
        if record.get('complete') or record.get('retry_after', 0) > time.time() or not remaining: continue
        if not resources_ok(root): break
        remaining -= 1
        try:
            queue_workbook(root, item, context)
            record.update(complete=True, metadata=item)
            record.pop('error', None); record.pop('retry_after', None)
        except Exception as error:
            record.update(error=str(error), retry_after=time.time()+1800)
        save(state_path, state)
    catalogues = json.loads((root / 'catalogues.json').read_text())
    for item in catalogues:
        url = item['url']; old = state['catalogues'].get(url, {})
        if old.get('complete') or old.get('retry_after', 0) > time.time() or not remaining:
            continue
        if not resources_ok(root): break
        remaining -= 1
        try:
            name = hashlib.sha256(url.encode()).hexdigest()
            path = evidence / ('catalogue-' + name + '.html')
            fetch(url, path, context, 5 * 1024**2)
            parser = Links(url); parser.feed(path.read_text(errors='replace'))
            if not parser.urls: raise ValueError('No supported PDF download links; source review needed')
            state['catalogues'][url] = dict(complete=True, html_sha256=digest(path), urls=sorted(parser.urls), metadata=item)
            for source in parser.urls:
                state['downloads'].setdefault(source, {'catalogue_url': url, 'metadata': item})
        except Exception as error:
            state['catalogues'][url] = dict(error=str(error), retry_after=time.time()+1800)
        save(state_path, state)
    remaining = download_limit
    for url, record in state['downloads'].items():
        if record.get('complete') or record.get('retry_after', 0) > time.time() or not remaining: continue
        if not resources_ok(root): break
        remaining -= 1
        path = root / 'packages' / (hashlib.sha256(url.encode()).hexdigest() + '.pdf')
        try:
            fetch(url, path, context, 200 * 1024**2)
            with path.open('rb') as stream:
                if not stream.read(1024).lstrip().startswith(b'%PDF-'): raise ValueError('Downloaded source is not a PDF')
            h = digest(path)
            save(root / 'queue' / ('text-' + h + '.json'), dict(kind='pdf_text', package=path.name, sha256=h, source_url=url))
            record.update(complete=True, sha256=h, package=path.name, bytes=path.stat().st_size,
                          retrieved_at=datetime.now(timezone.utc).isoformat(), review_state='unverified_original')
            record.pop('error', None); record.pop('retry_after', None)
        except Exception as error:
            record.update(error=str(error), retry_after=time.time()+1800)
        save(state_path, state)
    # Completed text jobs automatically supply explicit <=25-page OCR batches.
    for record in state['downloads'].values():
        if not record.get('complete'): continue
        h = record['sha256']; prefix = evidence / ('pdf-' + h)
        manifest = prefix.with_suffix('.manifest.json')
        if not manifest.exists(): continue
        meta = json.loads(manifest.read_text())
        pages_file = prefix.with_suffix('.pages.jsonl')
        if meta['original_sha256'] != h or digest(pages_file) != meta['pages_jsonl_sha256']:
            raise ValueError('Text evidence checksum mismatch')
        pages = []
        with pages_file.open() as stream:
            for line in stream:
                row = json.loads(line)
                if not row['has_text']: pages.append(row['page'])
        for start in range(0, len(pages), 25):
            batch = pages[start:start+25]
            job = dict(kind='pdf_ocr', package=record['package'], sha256=h,
                       source_url=meta['source_url'], pages=batch)
            key = hashlib.sha256(json.dumps(job, sort_keys=True).encode()).hexdigest()
            target = ocr / 'queue' / (key + '.json')
            if not target.exists(): save(target, job)
    state['updated_at'] = datetime.now(timezone.utc).isoformat()
    for item in json.loads(workbook_registry.read_text()) if workbook_registry.exists() else []:
        descriptor=root/'queue'/('workbook-'+item['key']+'.json')
        target=root/'queue'/('zz-profile-'+item['key']+'.json')
        if descriptor.exists() and not target.exists():
            job=json.loads(descriptor.read_text());job['kind']='workbook_profile';save(target,job)
    state['pending_downloads'] = sum(not r.get('complete', False) for r in state['downloads'].values())
    active_workbook_urls = {item['source_url'] for item in
                            (json.loads(workbook_registry.read_text()) if workbook_registry.exists() else [])}
    state['pending_workbooks'] = sum(not state.get('workbook_downloads', {}).get(url, {}).get('complete', False)
                                     for url in active_workbook_urls)
    state['pending_catalogues'] = sum(not state['catalogues'].get(r['url'], {}).get('complete', False) for r in catalogues)
    save(state_path, state)


def queue_workbook(root, item, context):
    """Preserve an official workbook and acquisition metadata for the raw-cell parser."""
    import re
    key = item['key']
    if not re.fullmatch('[a-z0-9][a-z0-9-]{0,120}', key): raise ValueError('Invalid workbook key')
    url = item['source_url']
    if not urlsplit(url).path.lower().endswith('.xlsx'): raise ValueError('Expected XLSX source')
    original = root / 'packages' / (key + '.xlsx')
    fetch(url, original, context, 200 * 1024**2)
    with zipfile.ZipFile(original) as book:
        if 'xl/workbook.xml' not in book.namelist(): raise ValueError('Not an XLSX workbook')
    acquisition = dict(item, original_sha256=digest(original), retrieved_at=datetime.now(timezone.utc).isoformat(),
                       evidence_kind='acquisition_metadata', review_state='unverified_raw_workbook',
                       warning='Source edition does not establish observation dates for all indicators.')
    body = json.dumps(acquisition).encode()
    manifest = {'version':1,'sources':[dict(item, sha256=digest(original),
                  extracted_sha256=hashlib.sha256(body).hexdigest(),
                  options={'evidence_kind':'acquisition_metadata','review_state':'unverified_raw_workbook'})]}
    package = root / 'packages' / (key + '.zip')
    temporary = package.with_suffix('.partial')
    with zipfile.ZipFile(temporary,'w',zipfile.ZIP_DEFLATED) as target:
        target.write(original,key+'.xlsx');target.writestr(key+'.json',body)
        target.writestr('manifest.json',json.dumps(manifest))
    temporary.replace(package)
    save(root/'queue'/('workbook-'+key+'.json'),dict(package=package.name,sha256=digest(package)))
