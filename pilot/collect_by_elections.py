"""Preserve ECI by-election sources and raw tables without guessing constituency identities."""
import argparse
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
from urllib.parse import urljoin, urlparse, urldefrag, quote

from bs4 import BeautifulSoup
from collect_election_archive import collect, fetch, key, save_manifest, intact_collection
from discover_assembly_archive import URL


def discover(body):
    soup = BeautifulSoup(json.loads(body)['cmsPagesData']['page_content'], 'html.parser')
    anchors = []
    for table in soup.select('table'):
        if table.select('a[href*="/statistical-report/be/"]'):
            anchors.extend(table.select('a[href]'))
    anchors.extend(a for a in soup.select('a[href]') if '1952 to 1995' in a.get_text(' ', strip=True))
    entries, seen = [], set()
    for a in anchors:
        label = a.get_text(' ', strip=True)
        years = re.findall(r'(?:19|20)\d{2}', label)
        if not years:
            continue
        href = a['href'].strip()
        url = urljoin('https://www.eci.gov.in/', href) if href != '#' else None
        if url and (urlparse(url).scheme != 'https' or urlparse(url).hostname not in ['www.eci.gov.in', 'old.eci.gov.in']):
            raise ValueError('Unexpected source host')
        identity = url or label
        if identity in seen:
            continue
        seen.add(identity)
        entries.append({'id': key(identity), 'label': label, 'year': int(years[0]), 'years': list(map(int, years)),
                        'url': url, 'status': 'pending' if url else 'missing_official_link'})
    if not entries:
        raise ValueError('No by-election table discovered')
    return {'source_url': URL, 'retrieved_at': datetime.now(timezone.utc).isoformat(),
            'source_sha256': hashlib.sha256(body).hexdigest(), 'entries': entries}


def raw_tables(path):
    if path.suffix.lower() in ['.xls', '.xlsx']:
        from extract_assembly_modern import load_cells
        book = load_cells(path)
        try:
            return [{'name': sheet.title, 'rows': [{'row': i, 'cells': list(row)} for i, row in enumerate(sheet.values, 1)
                     if any(v is not None and v != '' for v in row)]} for sheet in book]
        finally:
            book.close()
    if path.suffix.lower() in ['.html', '.htm']:
        soup = BeautifulSoup(path.read_bytes(), 'html.parser')
        return [{'name': 'HTML table '+str(i), 'rows': [{'row': j, 'cells': [c.get_text(' ', strip=True) for c in tr.find_all(['td', 'th'], recursive=False)],
                 'spans': [{'colspan': c.get('colspan', '1'), 'rowspan': c.get('rowspan', '1')} for c in tr.find_all(['td', 'th'], recursive=False)]}
                 for j, tr in enumerate(table.select('tr'), 1)]} for i, table in enumerate(soup.select('table'), 1)]
    return []


def collect_entry(entry, root):
    if not entry['url']:
        return entry
    url = entry['url']
    folder = root/entry['id']
    folder.mkdir(parents=True, exist_ok=True)
    manifest_path = folder/'manifest.json'
    if manifest_path.exists():
        previous = manifest_path.read_bytes()
        backup = folder/('manifest-'+hashlib.sha256(previous).hexdigest()+'.json')
        if not backup.exists():
            backup.write_bytes(previous)
    if '/files/file/' in url or '/statistical-report/be/' in url:
        record = json.loads(manifest_path.read_text(encoding='utf-8')) if intact_collection(url, root) else collect('be', str(entry['year'])+' '+entry['label'], url, root)
    else:
        if intact_collection(url, root):
            record = json.loads(manifest_path.read_text(encoding='utf-8'))
        else:
            record = {'kind': 'be', 'label': entry['label'], 'year': entry['year'], 'url': url, 'files': [], 'errors': [], 'status': 'collecting'}
            pending, seen = [url], set()
            while pending:
                current = pending.pop(0)
                if current in seen:
                    continue
                if len(seen) >= 200:
                    record['errors'].append('Linked-page limit reached; further sources remain pending.')
                    break
                seen.add(current)
                try:
                    body = fetch(current, folder/'download.part')
                    suffix = Path(urlparse(current).path).suffix.lower()
                    if suffix in ['.xls', '.xlsx', '.pdf']:
                        valid = body.startswith(b'PK') if suffix == '.xlsx' else body.startswith(b'%PDF-') if suffix == '.pdf' else body.startswith(bytes.fromhex('d0cf11e0a1b11ae1'))
                        if not valid:
                            raise ValueError('Official file signature does not match')
                    else:
                        suffix = '.html'
                        soup = BeautifulSoup(body, 'html.parser')
                        if not soup.select('table'):
                            raise ValueError('No result table found in HTML source')
                        for anchor in soup.select('a[href]'):
                            link = quote(urldefrag(urljoin(current, anchor['href']))[0], safe=':/?=&%')
                            parsed = urlparse(link)
                            if parsed.scheme == 'https' and parsed.hostname == 'old.eci.gov.in' and parsed.path.startswith('/ByeElection/') and Path(parsed.path).suffix.lower() in ['.html', '.htm', '.xls', '.xlsx', '.pdf'] and link not in seen:
                                pending.append(link)
                    digest = hashlib.sha256(body).hexdigest()
                    name = digest+suffix
                    (folder/name).write_bytes(body)
                    record['files'].append({'file': name, 'name': Path(urlparse(current).path).name, 'sha256': digest, 'bytes': len(body), 'source_url': current})
                except Exception as error:
                    record['errors'].append({'url': current, 'error': str(error)})
                finally:
                    (folder/'download.part').unlink(missing_ok=True)
            record['status'] = 'collected' if record['files'] and not record['errors'] else 'partial' if record['files'] else 'failed'
            save_manifest(manifest_path, record)
    extracted, errors = [], []
    for file in record['files']:
        path = folder/file['file']
        try:
            if hashlib.sha256(path.read_bytes()).hexdigest() != file['sha256']:
                raise ValueError('Archived source checksum mismatch')
            tables = raw_tables(path)
            if not tables:
                continue
            output = {'source_file': file['file'], 'source_sha256': file['sha256'], 'source_url': file.get('source_url', file.get('source_page', url)),
                      'status': 'raw_tables_need_mapping', 'note': 'Original cells and layout spans only. Headers, totals, formulas and geographic identities require mapping; do not sum overlapping tables.', 'tables': tables}
            name = file['sha256']+'-tables.json'
            body = json.dumps(output, ensure_ascii=False, default=str).encode('utf-8')
            if (folder/name).exists() and (folder/name).read_bytes() != body:
                previous = (folder/name).read_bytes()
                (folder/(file['sha256']+'-tables-'+hashlib.sha256(previous).hexdigest()+'.json')).write_bytes(previous)
            (folder/name).write_bytes(body)
            extracted.append({'file': name, 'name': file['name'], 'sha256': hashlib.sha256(body).hexdigest(), 'tables': len(tables), 'nonempty_rows': sum(len(t['rows']) for t in tables)})
        except Exception as error:
            errors.append({'file': file['file'], 'error': str(error)})
    record.update(extractions=extracted, extraction_errors=errors, checked_at=datetime.now(timezone.utc).isoformat())
    save_manifest(manifest_path, record)
    result = entry | {'status': record['status'], 'files': len(record['files']), 'extracted_files': len(extracted), 'raw_rows': sum(e['nonempty_rows'] for e in extracted),
                      'errors': record['errors']+errors}
    print(entry['label']+': '+result['status']+'; '+str(result['files'])+' files; '+str(result['raw_rows'])+' raw rows', flush=True)
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('destination', type=Path)
    parser.add_argument('--saved', type=Path)
    parser.add_argument('--workers', type=int, choices=range(1, 5), default=3)
    args = parser.parse_args()
    args.destination.mkdir(parents=True, exist_ok=True)
    body = args.saved.read_bytes() if args.saved else fetch(URL, args.destination/'catalogue-source.json')
    (args.destination/('catalogue-source-'+hashlib.sha256(body).hexdigest()+'.json')).write_bytes(body)
    catalogue = discover(body)
    save_manifest(args.destination/'catalogue.json', catalogue)
    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        results = list(pool.map(lambda e: collect_entry(e, args.destination), catalogue['entries']))
    save_manifest(args.destination/'summary.json', {'source_url': URL, 'checked_at': datetime.now(timezone.utc).isoformat(), 'entries': results})
