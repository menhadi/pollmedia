"""Discover and preserve official 1991 PCA workbooks without changing source cells."""
import argparse
from concurrent.futures import ProcessPoolExecutor, as_completed
from datetime import date, datetime, time, timedelta, timezone
import hashlib
import io
import json
from pathlib import Path
import re
import urllib.parse
import urllib.request
import zipfile

from bs4 import BeautifulSoup
import openpyxl

BASE = 'https://censusindia.gov.in/nada/index.php/catalog/'
TITLE = re.compile(r'^Primary Census Abstract \((Rural|Urban|SC|ST)\) - (.+) - 1991$')


def checksum(path):
    digest = hashlib.sha256()
    with path.open('rb') as stream:
        while block := stream.read(1024 * 1024):
            digest.update(block)
    return digest.hexdigest()


def write_extraction(path, data):
    temporary = path.with_suffix('.tmp')
    with temporary.open('w', encoding='utf-8') as stream:
        json.dump(data, stream, ensure_ascii=False, separators=(',', ':'))
    temporary.replace(path)
    return checksum(path)


def fetch(url):
    parsed = urllib.parse.urlparse(url)
    if parsed.scheme != 'https' or parsed.hostname != 'censusindia.gov.in':
        raise ValueError('Only the official Census HTTPS host is supported')
    with urllib.request.urlopen(url, timeout=60) as response:
        if urllib.parse.urlparse(response.url).hostname != parsed.hostname:
            raise ValueError('Unexpected download redirect')
        return response.read()


def extract(body):
    workbook = openpyxl.load_workbook(io.BytesIO(body), read_only=True, data_only=False)
    sheets = []
    try:
        for sheet in workbook:
            sheet.reset_dimensions()
            rows = []
            headers = None
            for number, cells in enumerate(sheet.iter_rows(), 1):
                values = [c.value.isoformat() if isinstance(c.value, (date, datetime, time)) else str(c.value) if isinstance(c.value, timedelta) else c.value for c in cells]
                if not any(value is not None for value in values):
                    continue
                if headers is None:
                    headers = values
                flags = []
                if any(isinstance(c.value, (date, datetime, time, timedelta)) for c in cells):
                    flags.append('An Excel date/time-formatted value is preserved as text; check the original workbook before interpreting it as a name or count.')
                if number > 1 and all(key in headers for key in ['T_POPLN', 'T_M_POPLN', 'T_F_POPLN']):
                    counts = [values[headers.index(key)] if headers.index(key) < len(values) else None for key in ['T_POPLN', 'T_M_POPLN', 'T_F_POPLN']]
                    if not all(type(value) in (int, float) and value >= 0 for value in counts):
                        flags.append('Population components are missing or non-numeric; original cells retained.')
                    elif counts[0] != counts[1] + counts[2]:
                        flags.append('Population differs from the reported male and female components.')
                rows.append({'source_row': number, 'cells': values,
                             'flags': flags,
                             'formula_columns': [i + 1 for i, c in enumerate(cells) if c.data_type == 'f'],
                             'error_columns': [i + 1 for i, c in enumerate(cells) if c.data_type == 'e']})
            sheets.append({'name': sheet.title, 'rows': rows})
    finally:
        workbook.close()
    return {'schema_version': 2, 'status': 'extracted_source_cells',
            'scope_note': '1991 source geography and population group. Sheets include headers, definitions and notes. Do not sum overlapping national/state/district rows or SC/ST subsets with general population. Blank cells remain null; formulas are preserved, not executed. Geographic and indicator mapping is pending.',
            'sheets': sheets}


def discover(folder):
    found = {}
    for page in range(1, 21):
        url = BASE + '?' + urllib.parse.urlencode({'sk': '1991-PCA', 'ps': 100, 'page': page})
        body = fetch(url)
        (folder / f'catalogue-{page}.html').write_bytes(body)
        soup = BeautifulSoup(body, 'html.parser')
        links = soup.select('h5 a')
        for link in links:
            name = link.get_text(' ', strip=True)
            match = TITLE.fullmatch(name)
            if match:
                landing = urllib.parse.urljoin(BASE, link['href'])
                found[landing] = {'name': name, 'landing': landing, 'year': 1991,
                                  'population_group': match[1], 'area_as_recorded': match[2]}
        if len(links) < 100:
            break
    else:
        raise ValueError('Catalogue pagination exceeded its safety limit')
    if not found:
        raise ValueError('No matching official catalogue entries')
    return sorted(found.values(), key=lambda entry: entry['landing'])


def collect(entry, root, saved=False):
    folder = root / entry['landing'].rsplit('/', 1)[-1]
    folder.mkdir(exist_ok=True)
    manifest_path = folder / 'manifest.json'
    if manifest_path.exists():
        result = json.loads(manifest_path.read_text(encoding='utf-8'))
        if result['landing'] != entry['landing']:
            raise ValueError('Saved source identity differs')
        for item in result['files']:
            for name, digest in [(item['file'], item['sha256']), (item['extraction'], item['extraction_sha256'])]:
                if Path(name).name != name or checksum(folder / name) != digest:
                    raise ValueError('Saved archive checksum differs')
            with (folder / item['extraction']).open(encoding='utf-8') as stream:
                prefix = stream.read(100)
            if not re.search(r'"schema_version"\s*:\s*2\b', prefix):
                previous = json.loads((folder / item['extraction']).read_text(encoding='utf-8'))
                data = extract((folder / item['file']).read_bytes())
                data.update({key: previous[key] for key in ['source_url', 'landing', 'source_sha256', 'year', 'population_group']})
                item['extraction'] = item['sha256'] + '.v2.json'
                item['extraction_sha256'] = write_extraction(folder / item['extraction'], data)
        manifest_path.write_text(json.dumps(result, indent=2), encoding='utf-8')
        return result
    if saved:
        if not (folder / 'catalogue.html').exists():
            raise ValueError('Not collected locally; download pending')
        body = (folder / 'catalogue.html').read_bytes()
    else:
        body = fetch(entry['landing'])
        (folder / 'catalogue.html').write_bytes(body)
    soup = BeautifulSoup(body, 'html.parser')
    urls = sorted({urllib.parse.urljoin(BASE, a['href']) for a in soup.select('a[href]')
                   if '/download/' in a['href'] and a['href'].lower().endswith('.xlsx')})
    if not urls:
        raise ValueError('No linked XLSX workbook: ' + entry['landing'])
    files = []
    for url in urls:
        if saved:
            cached = list(folder.glob('*.xlsx'))
            if len(urls) != 1 or len(cached) != 1 or checksum(cached[0]) != cached[0].stem:
                raise ValueError('Not collected locally; download pending')
            raw = cached[0].read_bytes()
        else:
            raw = fetch(url)
        digest = hashlib.sha256(raw).hexdigest()
        filename = digest + '.xlsx'
        extraction = digest + '.json'
        if not saved:
            (folder / filename).write_bytes(raw)
        data = extract(raw)
        data.update(source_url=url, landing=entry['landing'], source_sha256=digest,
                    year=1991, population_group=entry['population_group'])
        extraction_hash = write_extraction(folder / extraction, data)
        files.append({'url': url, 'file': filename, 'sha256': digest,
                      'extraction': extraction, 'extraction_sha256': extraction_hash,
                      'sheets': [{'name': s['name'], 'nonempty_rows': len(s['rows'])} for s in data['sheets']]})
    retrieved = datetime.fromtimestamp((folder / files[0]['file']).stat().st_mtime, timezone.utc).isoformat()
    result = dict(entry, retrieved_at=retrieved, files=files)
    manifest_path.write_text(json.dumps(result, indent=2), encoding='utf-8')
    return result


def run(root, output, saved=False):
    root.mkdir(parents=True, exist_ok=True)
    output.parent.mkdir(parents=True, exist_ok=True)
    catalogue = root / 'catalogue.json'
    if saved and not catalogue.exists():
        raise ValueError('No saved catalogue available')
    entries = json.loads(catalogue.read_text(encoding='utf-8')) if catalogue.exists() else discover(root)
    if not catalogue.exists():
        catalogue.write_text(json.dumps(entries, indent=2), encoding='utf-8')
    completed, failures = [], []
    with ProcessPoolExecutor(max_workers=1) as pool:
        pending = {pool.submit(collect, entry, root, saved): entry for entry in entries}
        for future in as_completed(pending):
            try:
                result = future.result()
                completed.append(result)
            except Exception as error:
                failures.append({'landing': pending[future]['landing'], 'error': str(error)})
                print(json.dumps(failures[-1]), flush=True)
            print(json.dumps({'collected': len(completed), 'failed': len(failures), 'catalogued': len(entries)}), flush=True)
    summary = {'catalogued': len(entries), 'collected': len(completed), 'complete': not failures,
               'pending_or_failed': failures,
               'workbooks': sum(len(e['files']) for e in completed),
               'source_sheet_rows_including_headers_and_notes': sum(s['nonempty_rows'] for e in completed for f in e['files'] for s in f['sheets'])}
    (root / 'summary.json').write_text(json.dumps(summary, indent=2), encoding='utf-8')
    if failures and not saved:
        raise ValueError('Some sources failed; rerun to retry without replacing verified files')
    if output.exists():
        raise ValueError('Choose a new snapshot filename')
    hashes = {}
    with zipfile.ZipFile(output, 'x', compression=zipfile.ZIP_DEFLATED) as archive:
        for path in sorted(root.rglob('*')):
            if path.is_file() and path.suffix != '.tmp':
                body = path.read_bytes(); name = path.relative_to(root).as_posix()
                hashes[name] = hashlib.sha256(body).hexdigest()
                archive.writestr(name, body)
        archive.writestr('checksums.json', json.dumps(hashes, indent=2))
    with zipfile.ZipFile(output) as archive:
        for name, digest in hashes.items():
            if hashlib.sha256(archive.read(name)).hexdigest() != digest:
                raise ValueError('Export checksum mismatch')
    output.with_suffix('.sha256').write_text(hashlib.sha256(output.read_bytes()).hexdigest() + '  ' + output.name + '\n', encoding='ascii')
    print(json.dumps(summary), flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--output', type=Path, required=True)
    parser.add_argument('--saved', action='store_true', help='Use only saved sources; export explicit pending-source coverage without networking')
    args = parser.parse_args()
    run(Path(__file__).resolve().parent / 'raw/census-1991-pca', args.output.resolve(), args.saved)
