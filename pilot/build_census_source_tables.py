"""Prepare bounded public pages from checksummed historical Census source cells."""
import argparse
from collections import defaultdict
import hashlib
import json
from pathlib import Path
import re
import zipfile

from collect_census_1991 import checksum, write_extraction

PAGE_SIZE = 100


def pages(stream, rows):
    result = []
    for start in range(0, len(rows), PAGE_SIZE):
        body = json.dumps(rows[start:start + PAGE_SIZE], ensure_ascii=False, separators=(',', ':')).encode('utf-8')
        if len(body) > 8 * 1024 * 1024:
            raise ValueError('Source page exceeds the supported response size')
        result.append({'offset': stream.tell(), 'length': len(body), 'sha256': hashlib.sha256(body).hexdigest()})
        stream.write(body + b'\n')
    return result


def prepare_sheet(stream, number, sheet):
    rows = sheet['rows']
    if not rows:
        return {'name': sheet['name'], 'headers': [], 'header_source_row': None, 'row_count': 0, 'pages': [], 'districts': []}
    header = rows[0]
    headers = header['cells'] + [None] * (max(len(r['cells']) for r in rows) - len(header['cells']))
    data = rows[1:]
    result = {'name': sheet['name'], 'headers': headers, 'header_source_row': header['source_row'],
              'row_count': len(data), 'pages': pages(stream, data), 'districts': []}
    district_columns = [i for i, value in enumerate(headers) if re.sub(r'[^a-z]', '', str(value).lower()) == 'districtname']
    if len(district_columns) == 1:
        column = district_columns[0]
        groups = defaultdict(list)
        for row in data:
            value = row['cells'][column] if column < len(row['cells']) else None
            groups[json.dumps(value, ensure_ascii=False)].append(row)
        for key, group in sorted(groups.items()):
            identity = hashlib.sha256(key.encode('utf-8')).hexdigest()[:16]
            label = json.loads(key)
            result['districts'].append({'id': identity, 'name': 'Not recorded' if label is None or label == '' else str(label),
                                        'row_count': len(group), 'pages': pages(stream, group)})
    return result


def build(archive, destination):
    destination.mkdir(parents=True, exist_ok=True)
    entries = json.loads((archive / 'catalogue.json').read_text(encoding='utf-8'))
    sources, pending = [], []
    for entry in entries:
        catalogue_id = entry['landing'].rsplit('/', 1)[-1]
        if not catalogue_id.isdigit():
            raise ValueError('Unexpected catalogue identifier')
        folder = archive / catalogue_id
        if not (folder / 'manifest.json').exists():
            pending.append(entry)
            continue
        manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
        if manifest['landing'] != entry['landing']:
            raise ValueError('Source identity differs')
        for item in manifest['files']:
            for filename, digest in [(item['file'], item['sha256']), (item['extraction'], item['extraction_sha256'])]:
                if Path(filename).name != filename or checksum(folder / filename) != digest:
                    raise ValueError('Source or extraction checksum differs')
            prepared_hash = hashlib.sha256(('source-pages-v2:' + item['extraction_sha256']).encode()).hexdigest()[:16]
            identity = f"{entry['year']}-{catalogue_id}-{prepared_hash}"
            target = destination / identity
            target.mkdir(exist_ok=True)
            metadata = target / 'manifest.json'
            if metadata.exists():
                prepared = json.loads(metadata.read_text(encoding='utf-8'))
                if checksum(target / 'pages.jsonl') != prepared['pages_sha256']:
                    raise ValueError('Prepared page checksum differs')
            else:
                data = json.loads((folder / item['extraction']).read_text(encoding='utf-8'))
                if data['source_sha256'] != item['sha256'] or data['landing'] != entry['landing']:
                    raise ValueError('Extraction provenance differs')
                with (target / 'pages.tmp').open('wb') as stream:
                    sheets = [prepare_sheet(stream, i, sheet) for i, sheet in enumerate(data['sheets'])]
                (target / 'pages.tmp').replace(target / 'pages.jsonl')
                prepared = dict(entry, id=identity, source_url=item['url'], source_sha256=item['sha256'],
                                extraction_sha256=item['extraction_sha256'], scope_note=data['scope_note'],
                                retrieved_at=manifest['retrieved_at'], sheets=sheets, pages_sha256=checksum(target / 'pages.jsonl'))
                write_extraction(metadata, prepared)
                del data
            sources.append(dict(entry, id=identity, manifest_sha256=checksum(metadata)))
            print(json.dumps({'source': identity, 'rows': sum(s['row_count'] for s in prepared['sheets'])}), flush=True)
    index = {'sources': sources, 'pending': pending, 'page_size': PAGE_SIZE,
             'scope_note': 'Original historical Census tables. Definitions, geography and population groups differ between sources. Do not add overlapping rows or compare years without checking their definitions.'}
    write_extraction(destination / 'index.json', index)
    return index


def bundle(destination, output):
    if output.exists() or output.with_suffix('.partial').exists():
        raise ValueError('Choose a new bundle filename')
    index = json.loads((destination / 'index.json').read_text(encoding='utf-8'))
    files = [destination / 'index.json']
    for entry in index['sources']:
        if not re.fullmatch(r'\d{4}-\d+-[a-f0-9]{16}', entry['id']):
            raise ValueError('Invalid source folder')
        folder = destination / entry['id']
        if checksum(folder / 'manifest.json') != entry['manifest_sha256']:
            raise ValueError('Source manifest checksum differs')
        metadata = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
        if checksum(folder / 'pages.jsonl') != metadata['pages_sha256']:
            raise ValueError('Source page checksum differs')
        files.extend([folder / 'manifest.json', folder / 'pages.jsonl'])
    hashes = {}
    output.parent.mkdir(parents=True, exist_ok=True)
    temporary = output.with_suffix('.partial')
    with zipfile.ZipFile(temporary, 'x', compression=zipfile.ZIP_DEFLATED, compresslevel=1) as archive:
        for path in files:
            name = 'application/storage/app/private/census-source-tables/' + path.relative_to(destination).as_posix()
            hashes[name] = checksum(path)
            archive.write(path, name)
        archive.writestr('manifest.json', json.dumps({'scope': 'Prepared historical Census pages for Pollmedia; original workbooks preserved in the separate source archive.', 'workbooks': len(index['sources']), 'pending': len(index['pending']), 'files': hashes}, indent=2))
    with zipfile.ZipFile(temporary) as archive:
        for name, digest in hashes.items():
            check = hashlib.sha256()
            with archive.open(name) as stream:
                while block := stream.read(1024 * 1024):
                    check.update(block)
            if check.hexdigest() != digest:
                raise ValueError('Bundle verification failed')
    temporary.rename(output)
    output.with_suffix('.sha256').write_bytes((checksum(output) + '  ' + output.name + '\n').encode('ascii'))
    print(json.dumps({'bundle': str(output), 'verified_files': len(hashes), 'workbooks': len(index['sources'])}), flush=True)


if __name__ == '__main__':
    root = Path(__file__).resolve().parents[1]
    parser = argparse.ArgumentParser()
    parser.add_argument('--archive', type=Path, default=root / 'pilot/raw/census-1991-pca')
    parser.add_argument('--destination', type=Path, default=root / 'application/storage/app/private/census-source-tables')
    parser.add_argument('--bundle', type=Path)
    arguments = parser.parse_args()
    result = build(arguments.archive, arguments.destination)
    print(json.dumps({'prepared_workbooks': len(result['sources']), 'pending_catalogue_entries': len(result['pending'])}))
    if arguments.bundle:
        bundle(arguments.destination, arguments.bundle)
