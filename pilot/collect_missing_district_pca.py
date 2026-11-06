"""Stage missing district PCA editions from verified official Census catalogue links.

Reuses the existing bounded extractor and versioned package importer. Original
workbooks, catalogue evidence and checksums are retained; map geometry is untouched.
"""
import argparse
import concurrent.futures
import hashlib
import html
import json
import re
import ssl
import urllib.parse
import urllib.request
import zipfile
from datetime import datetime, timezone
from pathlib import Path

from extract_import import extract

GEO = ['State', 'District', 'Subdistt', 'Town/Village', 'Ward', 'EB', 'Level', 'Name', 'TRU']


def stage(entry, root, context):
    landing = entry['landing']
    if not re.fullmatch(r'https://censusindia\.gov\.in/nada/index\.php/catalog/\d+', landing):
        raise ValueError('Unexpected catalogue origin')
    catalogue = landing.rsplit('/', 1)[1]
    folder = root / catalogue
    folder.mkdir(exist_ok=True)
    ready = folder / 'ready.json'
    if ready.exists():
        item = json.loads(ready.read_text())
        for name, checksum in [('original.xlsx', item['sha256']), ('extracted.json', item['extracted_sha256'])]:
            if hashlib.sha256((folder / name).read_bytes()).hexdigest() != checksum:
                raise ValueError('Staged checksum changed')
        return item
    page = folder / 'related-materials.html'
    if not page.exists():
        with urllib.request.urlopen(landing + '/related-materials', context=context, timeout=45) as response:
            page.write_bytes(response.read(2_000_001))
    downloads = []
    for anchor in re.findall(r'<a\b[^>]*>', page.read_text(), re.S):
        attributes = dict(re.findall(r'([\w-]+)="([^"]*)"', anchor))
        filename = html.unescape(attributes.get('data-filename', ''))
        if re.fullmatch(r'DDW_PCA\d{4}_2011_MDDS with UI\.xlsx', filename):
            url = html.unescape(attributes['href'])
            if not re.fullmatch(re.escape(landing) + r'/download/\d+', url):
                raise ValueError('Download origin differs from verified catalogue')
            downloads.append((url + '/' + urllib.parse.quote(filename), filename))
    if len(downloads) != 1:
        raise ValueError('Expected exactly one official 2011 district PCA workbook')
    url, filename = downloads[0]
    original = folder / 'original.xlsx'
    timestamp = folder / 'retrieved-at.txt'
    if not original.exists():
        with urllib.request.urlopen(url, context=context, timeout=60) as response:
            raw = response.read(20_000_001)
        if not raw.startswith(b'PK') or len(raw) > 20_000_000:
            raise ValueError('Not a bounded XLSX response')
        with zipfile.ZipFile(__import__('io').BytesIO(raw)) as archive:
            if archive.testzip() is not None:
                raise ValueError('Original workbook integrity failure')
        temporary = original.with_suffix('.partial')
        temporary.write_bytes(raw)
        temporary.replace(original)
        timestamp.write_text(datetime.now(timezone.utc).strftime('%Y-%m-%d %H:%M:%S'))
    import openpyxl
    workbook = openpyxl.load_workbook(original, read_only=True, data_only=False)
    try:
        sheets = [sheet.title for sheet in workbook if [c.value for c in next(sheet.iter_rows())][:9] == GEO]
    finally:
        workbook.close()
    if len(sheets) != 1:
        raise ValueError('No unique worksheet with the established Census geography headers')
    options = {'sheet': sheets[0], 'header_row': 1, 'max_rows': 20000, 'key_columns': GEO}
    data = extract(original, 'xlsx', options)
    if not {'TOT_P', 'TOT_M', 'TOT_F', 'P_06', 'P_LIT', 'No_HH'}.issubset(data['headers']):
        raise ValueError('Population, household or literacy fields missing')
    expected_state = re.search(r'DDW_PCA(\d{2})', filename).group(1)
    if {r['State'] for r in data['rows']} != {expected_state}:
        raise ValueError('Workbook state differs from its official resource identifier')
    if len({r['District'] for r in data['rows']}) != 1:
        raise ValueError('Expected one complete Census district inventory')
    seen = set()
    for row in data['rows']:
        identity = tuple(row[column] for column in GEO)
        if identity in seen:
            raise ValueError('Duplicate complete geographic identity')
        seen.add(identity)
    key = 'district-pca-2011-' + catalogue
    extracted = json.dumps(data, ensure_ascii=False, separators=(',', ':')).encode()
    (folder / 'extracted.json').write_bytes(extracted)
    item = {'key': key, 'source_url': url, 'sha256': hashlib.sha256(original.read_bytes()).hexdigest(),
            'extracted_sha256': hashlib.sha256(extracted).hexdigest(), 'retrieved_at': timestamp.read_text(),
            'options': options, 'row_count': len(data['rows']), 'catalogue': catalogue,
            'village_rows': sum(r['Level'].upper() == 'VILLAGE' and r['TRU'] == 'Rural' for r in data['rows']),
            'config': {'year': 2011, 'name': entry['title'].split(' 2011 01/')[0], 'url': url,
                       'landing': landing, 'format': 'xlsx', 'sheet': sheets[0]}}
    ready.write_text(json.dumps(item, ensure_ascii=False))
    return item


def stage_job(entry, root, ca):
    context = ssl.create_default_context()
    if ca:
        context.load_verify_locations(ca)
    return stage(entry, root, context)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('root', type=Path)
    parser.add_argument('--ca', type=Path)
    args = parser.parse_args()
    root = args.root
    existing = json.loads((root / 'existing.json').read_text())
    published = {re.search(r'/catalog/(\d+)', r['source_url']).group(1)
                 for r in existing if r['year'] == 2011 and r['status'] == 'published'
                 and 'DDW_PCA' in r['source_url'] and re.search(r'/catalog/(\d+)', r['source_url'])}
    entries = [r for r in json.loads((root / 'catalogues.json').read_text()) if r['landing'].rsplit('/', 1)[1] not in published]
    results, failures = [], []
    print(f'Skipping {len(published)} published catalogues; staging {len(entries)} missing districts', flush=True)
    with concurrent.futures.ProcessPoolExecutor(max_workers=3) as pool:
        jobs = {pool.submit(stage_job, entry, root, args.ca): entry for entry in entries}
        for future in concurrent.futures.as_completed(jobs):
            entry = jobs[future]
            try:
                item = future.result()
                results.append(item)
                print(item['catalogue'], item['village_rows'], 'villages', flush=True)
            except Exception as error:
                failures.append({'landing': entry['landing'], 'error': str(error)})
                print('FAILED', entry['landing'], str(error), flush=True)
    results.sort(key=lambda r: int(r['catalogue']))
    (root / 'sources.json').write_text(json.dumps({r['key']: r['config'] for r in results}, ensure_ascii=False, indent=2))
    packages = []
    for start in range(0, len(results), 20):
        batch = results[start:start + 20]
        path = root / f'package-{start // 20:03}.zip'
        if path.exists():
            path.unlink()
        with zipfile.ZipFile(path, 'x', zipfile.ZIP_DEFLATED) as archive:
            for item in batch:
                folder = root / item['catalogue']
                archive.write(folder / 'original.xlsx', item['key'] + '.xlsx')
                archive.write(folder / 'extracted.json', item['key'] + '.json')
            archive.writestr('manifest.json', json.dumps({'version': 1, 'sources': [{k: v for k, v in r.items() if k not in ['config', 'catalogue', 'village_rows']} for r in batch]}))
        packages.append({'file': str(path), 'sha256': hashlib.sha256(path.read_bytes()).hexdigest(), 'sources': len(batch)})
    report = {'skipped_published': len(published), 'districts_staged': len(results),
              'village_rows': sum(r['village_rows'] for r in results), 'failures': failures, 'packages': packages}
    (root / 'report.json').write_text(json.dumps(report, indent=2))
    print(json.dumps(report), flush=True)


if __name__ == '__main__':
    main()

