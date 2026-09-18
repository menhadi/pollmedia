"""Collect official catalogue editions without assuming historical constituency identities."""
import argparse
import concurrent.futures
import hashlib
import json
import re
import subprocess
import shutil
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlparse, urljoin, urldefrag
from bs4 import BeautifulSoup


def key(url):
    return hashlib.sha256(url.encode()).hexdigest()[:24]


def fetch(url, path):
    parsed = urlparse(url)
    if parsed.scheme != 'https' or parsed.hostname not in ['old.eci.gov.in','www.eci.gov.in']:
        raise ValueError('This adapter accepts only official ECI hosts.')
    path.parent.mkdir(parents=True, exist_ok=True)
    result = subprocess.run([shutil.which('curl.exe') or 'curl', '--silent', '--show-error', '--fail', '--location', '--proto', '=https', '--proto-redir', '=https', '--max-time', '50', '--max-filesize', '104857600', url, '-o', str(path)], capture_output=True)
    if result.returncode:
        path.unlink(missing_ok=True)
        raise ValueError('Official download failed (curl code ' + str(result.returncode) + ').')
    return path.read_bytes()


def report_pages(soup):
    return list(dict.fromkeys(a['href'].split('?')[0] for a in soup.select('.cDownloadsCategoryTable a[title^="View the file"]') if '/files/file/' in a.get('href','')))


def save_manifest(path, record):
    temp = path.with_suffix('.tmp')
    temp.write_text(json.dumps(record, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    temp.replace(path)


def collect_modern(kind, label, url, folder, manifest, old, record):
    modern_ac = re.fullmatch(r'/statistical-report/'+('be' if kind == 'be' else 'ae')+r'/(20\d{2})/(\d+)', urlparse(url).path) if kind in ['ac', 'be'] else None
    static_path = Path(__file__).resolve().parents[1] / 'application/database/fixtures/eci-assembly-static.json'
    static = json.loads(static_path.read_text(encoding='utf-8')) if static_path.exists() else {'editions': {}}
    static_entries = static['editions'].get(url) if kind == 'ac' else None
    if not static_entries and not modern_ac and (kind != 'pc' or int(label[:4]) != 2024):
        record.update(status='adapter_required', errors=['No verified catalogue API mapping for this edition.'])
        save_manifest(manifest, record)
        return record
    previous = {item['download_id']: item for item in old.get('files', [])}
    def retain(item):
        path = folder / item['file']
        if Path(item['file']).name == item['file'] and path.is_file() and hashlib.sha256(path.read_bytes()).hexdigest() == item['sha256']:
            record['files'].append(item)
    try:
        category = modern_ac.group(2) if modern_ac else '1'
        catalogue_url = 'https://www.eci.gov.in/eci-backend/public/api/election-result?category_id=' + category
        if static_entries:
            catalogue_url = static['source_url']
            catalogue = {'results': static_entries, 'totalResults': len(static_entries), 'source_sha256': static['source_sha256']}
            body = json.dumps(catalogue).encode('utf-8')
            (folder / 'modern-catalogue.json').write_bytes(body)
        else:
            body = fetch(catalogue_url, folder / 'modern-catalogue.json')
            catalogue = json.loads(body)
        entries = catalogue['results']
        if not entries or len(entries) > 500:
            raise ValueError('Empty or oversized official catalogue')
        if 'totalResults' in catalogue and int(catalogue['totalResults']) != len(entries):
            raise ValueError('Catalogue pagination is incomplete')
        record.update(catalogue_url=catalogue_url, catalogue_sha256=hashlib.sha256(body).hexdigest(), report_count=len(entries))
        seen = set()
        expected = 0
        for entry in entries:
            for field in ['pdf_zip_url', 'xlsx_url']:
                source = entry.get(field)
                if not source or source in seen:
                    continue
                seen.add(source)
                expected += 1
                extension = Path(urlparse(source).path).suffix.lower()
                title = entry['title'].strip()
                download_id = key(source)
                if kind == 'pc' and field == 'pdf_zip_url' and title.startswith('32.'):
                    download_id = key(url) + '-summary'
                elif kind == 'pc' and field == 'pdf_zip_url' and title.startswith('33.'):
                    download_id = key(url) + '-saved'
                temporary = folder / (download_id + '.part')
                try:
                    if extension not in ['.pdf', '.xls', '.xlsx', '.zip', '.csv']:
                        raise ValueError('Unsupported file type in official catalogue')
                    data = fetch(source, temporary)
                    valid = (extension == '.pdf' and data.startswith(b'%PDF-')) or (extension in ['.xlsx', '.zip'] and data.startswith(b'PK')) or (extension == '.xls' and data.startswith(bytes.fromhex('d0cf11e0a1b11ae1')))
                    if not valid:
                        raise ValueError('File signature does not match the catalogue format')
                    sha = hashlib.sha256(data).hexdigest()
                    destination = folder / (download_id + extension)
                    if destination.exists() and hashlib.sha256(destination.read_bytes()).hexdigest() != sha:
                        backup = folder / (download_id + '-' + hashlib.sha256(destination.read_bytes()).hexdigest() + extension)
                        if not backup.exists():
                            backup.write_bytes(destination.read_bytes())
                    temporary.replace(destination)
                    record['files'].append(dict(download_id=download_id, name=title + extension, file=destination.name, bytes=len(data), sha256=sha, source_page=url, source_url=source))
                except Exception as error:
                    temporary.unlink(missing_ok=True)
                    record['errors'].append(dict(name=title, source_url=source, reason=str(error)))
                    if download_id in previous:
                        retain(previous[download_id])
                save_manifest(manifest, record)
        record['expected_files'] = expected
        record['status'] = 'collected' if expected and len(record['files']) == expected and not record['errors'] else 'partial' if record['files'] else 'failed'
    except Exception as error:
        record['errors'].append(str(error))
        for item in previous.values():
            retain(item)
        record['status'] = 'partial' if record['files'] else 'failed'
    save_manifest(manifest, record)
    return record


def intact_collection(url, root):
    folder = root / key(url)
    try:
        record = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
        return record.get('url') == url and record.get('status') == 'collected' and bool(record.get('files')) and all(Path(f['file']).name == f['file'] and (folder / f['file']).is_file() and hashlib.sha256((folder / f['file']).read_bytes()).hexdigest() == f['sha256'] for f in record['files'])
    except (OSError, ValueError, KeyError):
        return False


def collect(kind, label, url, root):
    folder = root / key(url)
    folder.mkdir(parents=True, exist_ok=True)
    manifest = folder / 'manifest.json'
    old = json.loads(manifest.read_text(encoding='utf-8')) if manifest.exists() else {}
    if manifest.exists():
        body = manifest.read_bytes()
        backup = folder / ('manifest-' + hashlib.sha256(body).hexdigest() + '.json')
        if not backup.exists(): backup.write_bytes(body)
    record = dict(kind=kind, label=label, year=int(label[:4]), url=url, checked_at=datetime.now(timezone.utc).isoformat(), status='collecting', files=[], errors=[])
    save_manifest(manifest, record)
    if urlparse(url).hostname != 'old.eci.gov.in':
        return collect_modern(kind, label, url, folder, manifest, old, record)
    previous = {f['download_id']: f for f in old.get('files', [])}
    try:
        if '/files/category/' in url:
            pending, visited, pages = [url], set(), []
            while pending:
                current = pending.pop(0)
                if current in visited:
                    continue
                if len(visited) >= 30:
                    raise ValueError('Category pagination exceeded the supported limit.')
                visited.add(current)
                html = fetch(current, folder / ('category-' + key(current) + '.html'))
                soup = BeautifulSoup(html, 'html.parser')
                pages.extend(report_pages(soup))
                pending.extend(a['href'] for a in soup.select('a[rel="next"]') if a.get('href', '').startswith(url) and a['href'] not in visited)
            pages = list(dict.fromkeys(pages))
        else:
            pages = [url]
        if not pages:
            raise ValueError('No report pages found; source layout needs review.')
        for page in pages:
            try:
                html = fetch(page + '?do=download', folder / ('page-' + key(page) + '.html'))
                soup = BeautifulSoup(html, 'html.parser')
                report_title = soup.title.get_text(' ',strip=True).replace(' - Election Commission of India','') if soup.title else label
                agreement = next((a for a in soup.select('a[href]') if a.get_text(' ', strip=True) == 'Agree & Download'), None)
                if agreement:
                    notice = soup.get_text(' ', strip=True)
                    permitted = 'Disclaimer :- These reports are developed on the basis of information provided by Chief Electoral Officers of States and UTs.'
                    if permitted not in notice and 'In case of any dispute, the data maintained in the Statutory Forms by the concerned Returning Officers shall prevail.' not in notice:
                        raise ValueError('Download notice changed; requires review.')
                    html = fetch(urljoin(page, agreement['href']), folder / ('response-' + key(page) + '.html'))
                    if html.startswith(b'%PDF-') or html.startswith(b'PK'):
                        extension = '.pdf' if html.startswith(b'%PDF-') else '.zip'
                        file = key(page) + '-direct' + extension
                        destination = folder / file
                        if destination.exists():
                            previous_body = destination.read_bytes()
                            if previous_body != html:
                                backup = folder / (destination.stem + '-' + hashlib.sha256(previous_body).hexdigest() + destination.suffix)
                                if not backup.exists(): backup.write_bytes(previous_body)
                        destination.write_bytes(html)
                        record['files'].append(dict(download_id=key(page)+'-direct', name=report_title+extension, file=file, bytes=len(html), sha256=hashlib.sha256(html).hexdigest(), source_page=page))
                        save_manifest(manifest,record)
                        continue
                    soup = BeautifulSoup(html, 'html.parser')
                downloads = soup.select('a[data-action="download"]')
                if not downloads:
                    raise ValueError('No direct download links found; source layout or access notice needs review.')
                for link in downloads:
                    container = link.find_parent('li')
                    heading = container.select_one('h4') if container else None
                    filename = heading.get_text(' ', strip=True) if heading else 'Official report'
                    download_url = urljoin(page, link['href'])
                    match = re.search(r'(?:[?&])r=(\d+)', download_url)
                    download_id = key(page) + '-' + (match[1] if match else key(download_url))
                    try:
                        known = previous.get(download_id)
                        if known and (folder / known['file']).is_file() and hashlib.sha256((folder / known['file']).read_bytes()).hexdigest() == known['sha256']:
                            record['files'].append(known)
                            continue
                        temporary = folder / (download_id + '.part')
                        data = fetch(download_url, temporary)
                        extension = '.pdf' if data.startswith(b'%PDF-') else '.zip' if data.startswith(b'PK') else '.xls' if data.startswith(bytes.fromhex('d0cf11e0a1b11ae1')) else None
                        if extension is None:
                            temporary.unlink(missing_ok=True)
                            raise ValueError('Response was not a supported PDF, workbook or ZIP archive.')
                        if extension == '.zip' and filename.lower().endswith('.xlsx'):
                            extension = '.xlsx'
                        destination = folder / (download_id + extension)
                        temporary.replace(destination)
                        record['files'].append(dict(download_id=download_id, name=filename, file=destination.name, bytes=len(data), sha256=hashlib.sha256(data).hexdigest(), source_page=page))
                    except Exception as error:
                        record['errors'].append(dict(page=page, name=filename, reason=str(error)))
                save_manifest(manifest, record)
            except Exception as error:
                record['errors'].append(dict(page=page, reason=str(error)))
        record['status'] = 'collected' if record['files'] and not record['errors'] else 'partial' if record['files'] else 'failed'
    except Exception as error:
        record['errors'].append(str(error))
        record['status'] = 'partial' if record['files'] else 'failed'
    save_manifest(manifest, record)
    return record


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('catalogue')
    parser.add_argument('destination')
    parser.add_argument('--kind', choices=['ac','pc','all'], default='all')
    parser.add_argument('--year', type=int)
    parser.add_argument('--before-year', type=int)
    parser.add_argument('--missing', action='store_true', help='Skip complete collections after verifying every checksum')
    parser.add_argument('--workers', type=int, choices=range(1,5), default=4)
    parser.add_argument('--state', help='State as recorded, or all; use a national Assembly catalogue')
    args = parser.parse_args()
    catalogue = json.loads(Path(args.catalogue).read_text(encoding='utf-8'))
    if args.state:
        catalogue = {'ac': [[e.get('label', str(e['year'])) + ' ' + e['state'], e['url']] for e in catalogue['entries'] if args.state == 'all' or e['state'] == args.state], 'pc': []}
        if not catalogue['ac']:
            parser.error('No matching Assembly state')
    jobs = [(kind,label,url,Path(args.destination)) for kind in ['ac','pc'] if args.kind in [kind,'all'] for label,url in catalogue[kind] if args.year is None or int(label[:4]) == args.year]
    jobs = [j for j in jobs if args.before_year is None or int(j[1][:4]) < args.before_year]
    if args.missing:
        jobs = [j for j in jobs if not intact_collection(j[2], j[3])]
    print(str(len(jobs)) + ' editions to collect', flush=True)
    failures = 0
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
        futures = [pool.submit(collect,*job) for job in jobs]
        for future in concurrent.futures.as_completed(futures):
            result = future.result()
            failures += result["status"] != "collected"
            print(f"{result['kind']} {result['label']}: {result['status']}; {len(result['files'])} files; {len(result['errors'])} issues", flush=True)

    if failures:
        raise SystemExit(1)
