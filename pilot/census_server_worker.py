"""Resumable raw Census workbook extraction in an isolated server review area.

Queue files contain a local package filename and its expected SHA-256. The
package format is produced by export_census_package.py. No live DB access.
"""
import argparse
from contextlib import closing
from contextlib import nullcontext
from datetime import date, datetime, time, timedelta, timezone
import hashlib
import json
from pathlib import Path
import posixpath
import re
import shutil
import sqlite3
import time as clock
import zipfile
import xml.etree.ElementTree as ET
from ocr_civic_pdf_pages import ResourceWait


def digest(path):
    h = hashlib.sha256()
    with Path(path).open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            h.update(block)
    return h.hexdigest()


def status(root, **values):
    values['updated_at'] = datetime.now(timezone.utc).isoformat()
    temporary = root / 'status.tmp'
    temporary.write_text(json.dumps(values, sort_keys=True), encoding='utf-8')
    temporary.replace(root / 'status.json')


def connect(root):
    db = sqlite3.connect(root / 'census-review.sqlite')
    db.execute('PRAGMA foreign_keys=ON')
    db.executescript('''
        CREATE TABLE IF NOT EXISTS jobs (
            sha256 TEXT PRIMARY KEY, package TEXT NOT NULL, status TEXT NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0, retry_after REAL NOT NULL DEFAULT 0,
            error TEXT, completed_at TEXT);
        CREATE TABLE IF NOT EXISTS workbooks (
            sha256 TEXT PRIMARY KEY, file TEXT NOT NULL, status TEXT NOT NULL,
            row_count INTEGER NOT NULL DEFAULT 0);
        CREATE TABLE IF NOT EXISTS source_references (
            package_sha256 TEXT NOT NULL, source_key TEXT NOT NULL,
            workbook_sha256 TEXT NOT NULL REFERENCES workbooks(sha256),
            metadata_json TEXT NOT NULL, PRIMARY KEY(package_sha256, source_key));
        CREATE TABLE IF NOT EXISTS raw_rows (
            workbook_sha256 TEXT NOT NULL REFERENCES workbooks(sha256),
            sheet TEXT NOT NULL, source_row INTEGER NOT NULL,
            cells_json TEXT NOT NULL, cell_types_json TEXT NOT NULL,
            PRIMARY KEY(workbook_sha256, sheet, source_row));
        CREATE TABLE IF NOT EXISTS source_validations (
            review_sha256 TEXT NOT NULL, source_id TEXT NOT NULL,
            rows_checked INTEGER NOT NULL, completed_at TEXT NOT NULL,
            PRIMARY KEY(review_sha256, source_id));
    ''')
    return db


def resources_ok(root):
    if shutil.disk_usage(root).free < 10 * 1024**3:
        return False
    if Path('/proc/meminfo').exists():
        available = next(int(line.split()[1]) * 1024 for line in
                         Path('/proc/meminfo').read_text().splitlines()
                         if line.startswith('MemAvailable:'))
        minimum = int((root / 'minimum-memory-mib').read_text()) if (root / 'minimum-memory-mib').exists() else 1536
        if available < minimum * 1024**2:
            return False
    return True


def value_json(value):
    if isinstance(value, (datetime, date, time)):
        return value.isoformat()
    if isinstance(value, timedelta):
        return str(value)
    raise TypeError(type(value).__name__)


def xlsx_xml_rows(path):
    """Read raw OOXML cells when an otherwise valid workbook has invalid styles.

    Keep numeric lexemes and style/formula metadata as source evidence. This
    deliberately does not interpret dates, codes, or cached formula results.
    """
    ns = '{http://schemas.openxmlformats.org/spreadsheetml/2006/main}'
    rel_ns = '{http://schemas.openxmlformats.org/officeDocument/2006/relationships}'
    pkg_ns = '{http://schemas.openxmlformats.org/package/2006/relationships}'
    with zipfile.ZipFile(path) as archive:
        strings = []
        if 'xl/sharedStrings.xml' in archive.namelist():
            with archive.open('xl/sharedStrings.xml') as stream:
                for _, element in ET.iterparse(stream, events=('end',)):
                    if element.tag == ns + 'si':
                        parts = []
                        for child in element:
                            if child.tag == ns + 't':
                                parts.append(child.text or '')
                            elif child.tag == ns + 'r':
                                parts.extend(t.text or '' for t in child.findall(ns + 't'))
                        strings.append(''.join(parts))
                        element.clear()
        workbook = ET.fromstring(archive.read('xl/workbook.xml'))
        relations = ET.fromstring(archive.read('xl/_rels/workbook.xml.rels'))
        targets = {item.attrib['Id']: item.attrib['Target'] for item in relations.findall(pkg_ns + 'Relationship')}
        for sheet in workbook.find(ns + 'sheets').findall(ns + 'sheet'):
            target = targets[sheet.attrib[rel_ns + 'id']]
            member = posixpath.normpath(target.lstrip('/') if target.startswith('/') else 'xl/' + target)
            if not member.startswith('xl/worksheets/') or member not in archive.namelist():
                raise ValueError('Invalid OOXML worksheet path')
            with archive.open(member) as stream:
                root = None
                previous_row = 0
                for event, element in ET.iterparse(stream, events=('start', 'end')):
                    if root is None:
                        root = element
                    if event != 'end' or element.tag != ns + 'row':
                        continue
                    number = int(element.attrib.get('r', previous_row + 1))
                    if number <= previous_row:
                        raise ValueError('Invalid OOXML row index')
                    previous_row = number
                    values, types = [], []
                    for cell in element.findall(ns + 'c'):
                        match = re.match(r'^([A-Z]+)[0-9]+$', cell.attrib.get('r', ''))
                        if not match:
                            raise ValueError('Invalid OOXML cell address')
                        column = 0
                        for letter in match.group(1):
                            column = column * 26 + ord(letter) - 64
                        if column <= len(values) or column > 16384:
                            raise ValueError('Invalid OOXML column index')
                        values.extend([None] * (column - len(values) - 1))
                        types.extend(['blank'] * (column - len(types) - 1))
                        kind = cell.attrib.get('t', 'n')
                        raw = cell.find(ns + 'v')
                        formula = cell.find(ns + 'f')
                        cached = raw.text if raw is not None else None
                        if kind == 's' and cached is not None:
                            index = int(cached)
                            if index < 0 or index >= len(strings):
                                raise ValueError('Invalid OOXML shared-string index')
                            value = strings[index]
                        elif kind == 'inlineStr':
                            inline = cell.find(ns + 'is')
                            value = ''.join(t.text or '' for t in inline.iter(ns + 't')) if inline is not None else None
                        elif formula is not None and formula.text:
                            value = '=' + formula.text
                        else:
                            value = cached
                        values.append(value)
                        types.append({'type': kind, 'style': cell.attrib.get('s'),
                                      'formula': formula.text if formula is not None else None,
                                      'formula_attrs': formula.attrib if formula is not None else None,
                                      'cached': cached if formula is not None else None,
                                      'reader': 'raw_ooxml_invalid_styles'})
                    if any(value is not None for value in values):
                        yield sheet.attrib['name'], number, values, types
                    element.clear()
                    root.clear()


def workbook_rows(path):
    if path.suffix == '.zip':
        # Official LGD exports contain SpreadsheetML documents, sometimes named XLS.
        ns = '{urn:schemas-microsoft-com:office:spreadsheet}'
        with zipfile.ZipFile(path) as archive:
            for member in archive.infolist():
                if member.is_dir():
                    continue
                with archive.open(member) as stream:
                    worksheet, row_number, root = '', 0, None
                    for event, element in ET.iterparse(stream, events=('start', 'end')):
                        if root is None:
                            root = element
                            if root.tag != ns + 'Workbook':
                                raise ValueError('Unsupported LGD archive member: ' + member.filename)
                        if event == 'start' and element.tag == ns + 'Worksheet':
                            worksheet = element.attrib.get(ns + 'Name', '')
                            row_number = 0
                        if event == 'end' and element.tag == ns + 'Row':
                            row_number = int(element.attrib.get(ns + 'Index', row_number + 1))
                            values, types = [], []
                            for cell in element.findall(ns + 'Cell'):
                                index = int(cell.attrib.get(ns + 'Index', len(values) + 1))
                                if index <= len(values) or index > 10000:
                                    raise ValueError('Invalid SpreadsheetML cell index')
                                values.extend([None] * (index - len(values) - 1))
                                types.extend(['blank'] * (index - len(types) - 1))
                                data = cell.find(ns + 'Data')
                                values.append(''.join(data.itertext()) if data is not None else None)
                                types.append({'type': data.attrib.get(ns + 'Type') if data is not None else None,
                                              'formula': cell.attrib.get(ns + 'Formula'),
                                              'merge_across': cell.attrib.get(ns + 'MergeAcross'),
                                              'merge_down': cell.attrib.get(ns + 'MergeDown')})
                                merge = int(cell.attrib.get(ns + 'MergeAcross', '0'))
                                if merge < 0 or len(values) + merge > 10000:
                                    raise ValueError('Invalid SpreadsheetML merge span')
                                values.extend([None] * merge)
                                types.extend(['merged_placeholder'] * merge)
                            if any(v is not None for v in values):
                                yield member.filename + '/' + worksheet, row_number, values, types
                            element.clear()
    elif path.suffix == '.xlsx':
        import openpyxl
        try:
            book = openpyxl.load_workbook(path, read_only=True, data_only=False)
        except TypeError as error:
            if 'openpyxl.styles.fills.Fill' not in str(error):
                raise
            yield from xlsx_xml_rows(path)
            return
        try:
            for sheet in book:
                sheet.reset_dimensions()
                for number, cells in enumerate(sheet.iter_rows(), 1):
                    if any(cell.value is not None for cell in cells):
                        yield sheet.title, number, [c.value for c in cells], [c.data_type for c in cells]
        finally:
            book.close()
    elif path.suffix == '.xls':
        import xlrd
        book = xlrd.open_workbook(str(path), on_demand=True)
        try:
            for name in book.sheet_names():
                sheet = book.sheet_by_name(name)
                for number in range(sheet.nrows):
                    values = sheet.row_values(number)
                    if any(v not in (None, '') for v in values):
                        # XLS values are cached cells; formulas remain in the original.
                        yield name, number + 1, values, sheet.row_types(number)
                book.unload_sheet(name)
        finally:
            book.release_resources()
    else:
        raise ValueError('Unsupported workbook extension')


def preserve_member(archive, name, destination, expected):
    if not re.fullmatch('[a-f0-9]{64}', expected):
        raise ValueError('Invalid member checksum')
    if destination.exists() and digest(destination) == expected:
        return
    temporary = destination.with_suffix(destination.suffix + '.partial')
    with archive.open(name) as source, temporary.open('wb') as target:
        shutil.copyfileobj(source, target, 1024 * 1024)
    if digest(temporary) != expected:
        temporary.unlink()
        raise ValueError('Member checksum mismatch: ' + name)
    temporary.replace(destination)


def process_package(root, package, expected, db):
    if digest(package) != expected:
        raise ValueError('Package checksum mismatch')
    originals = root / 'originals'
    evidence = root / 'package-evidence' / expected
    originals.mkdir(exist_ok=True)
    evidence.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(package) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)) or sum(i.file_size for i in archive.infolist()) > 4 * 1024**3:
            raise ValueError('Duplicate members or oversized package')
        manifest_body = archive.read('manifest.json')
        manifest = json.loads(manifest_body)
        if manifest.get('version') != 1 or not isinstance(manifest.get('sources'), list):
            raise ValueError('Unsupported source manifest')
        (evidence / 'manifest.json').write_bytes(manifest_body)
        for item in manifest['sources']:
            key = item['key']
            if not re.fullmatch('[a-z0-9][a-z0-9-]{0,120}', key):
                raise ValueError('Invalid source key')
            candidates = [key + ext for ext in ('.xls', '.xlsx', '.zip') if key + ext in names]
            if len(candidates) != 1:
                raise ValueError('Missing or ambiguous workbook: ' + key)
            source_hash = item['sha256']
            if not re.fullmatch('[a-f0-9]{64}', source_hash):
                raise ValueError('Invalid source checksum')
            path = originals / (source_hash + Path(candidates[0]).suffix)
            preserve_member(archive, candidates[0], path, source_hash)
            preserve_member(archive, key + '.json', evidence / (key + '.json'), item['extracted_sha256'])
            db.execute('INSERT OR IGNORE INTO workbooks(sha256,file,status) VALUES (?,?,?)',
                       (source_hash, str(path.relative_to(root)), 'pending'))
            db.execute('INSERT OR REPLACE INTO source_references VALUES (?,?,?,?)',
                       (expected, key, source_hash, json.dumps(item, ensure_ascii=False)))
            db.commit()
            if db.execute('SELECT status FROM workbooks WHERE sha256=?', (source_hash,)).fetchone()[0] == 'complete':
                continue
            if not resources_ok(root):
                raise RuntimeError('Waiting for disk/RAM reserve')
            # Only incomplete staging sources are restarted; completed hashes are reused.
            db.execute('DELETE FROM raw_rows WHERE workbook_sha256=?', (source_hash,))
            db.execute('UPDATE workbooks SET status=?,row_count=0 WHERE sha256=?', ('extracting', source_hash))
            db.commit()
            count = 0
            for sheet, number, values, types in workbook_rows(path):
                db.execute('INSERT INTO raw_rows VALUES (?,?,?,?,?)', (source_hash, sheet, number,
                           json.dumps(values, ensure_ascii=False, default=value_json, allow_nan=False), json.dumps(list(types))))
                count += 1
                if count % 1000 == 0:
                    db.commit()
                    status(root, state='extracting', source=key, current_source_rows=count)
                    if not resources_ok(root):
                        raise RuntimeError('Waiting for disk/RAM reserve')
            db.execute('UPDATE workbooks SET status=?,row_count=? WHERE sha256=?', ('complete', count, source_hash))
            db.commit()


def validate_1991(root, package, expected, job, db):
    """Compare preserved review cells to the original XLSX, without editing review data."""
    review_path = Path(job['review_db']).resolve()
    review_hash = job['review_sha256']
    if 'app' in review_path.parts or 'application' in review_path.parts:
        raise ValueError('Review database must be outside the live application')
    if digest(package) != expected or digest(review_path) != review_hash:
        raise ValueError('1991 original package or review database checksum differs')
    with closing(sqlite3.connect(review_path.as_uri() + '?mode=ro', uri=True)) as review, zipfile.ZipFile(package) as archive:
        for identity, source_hash in review.execute('SELECT id,source_sha256 FROM sources ORDER BY id'):
            if db.execute('SELECT 1 FROM source_validations WHERE review_sha256=? AND source_id=?',
                          (review_hash, identity)).fetchone():
                continue
            if not resources_ok(root):
                raise RuntimeError('Waiting for disk/RAM reserve')
            members = [n for n in archive.namelist() if Path(n).name == source_hash + '.xlsx']
            if not members:
                raise ValueError('Missing original for review source: ' + identity)
            (root / 'originals').mkdir(exist_ok=True)
            path = root / 'originals' / (source_hash + '.xlsx')
            preserve_member(archive, members[0], path, source_hash)
            sheets = {name: (number, json.loads(headers), expected_rows) for number,name,headers,expected_rows in
                      review.execute('SELECT number,name,headers_json,expected_rows FROM sheets WHERE source_id=?', (identity,))}
            cursors, next_rows, seen = {}, {}, {}
            count = 0
            for sheet, number, values, types in workbook_rows(path):
                if sheet not in sheets:
                    raise ValueError('Unexpected original sheet: ' + sheet)
                values = json.loads(json.dumps(values, default=value_json, allow_nan=False))
                if sheet not in cursors:
                    index, headers, _ = sheets[sheet]
                    # Source-table builder pads headers to the widest data row.
                    while headers and headers[-1] is None:
                        headers.pop()
                    header_values = list(values)
                    while header_values and header_values[-1] is None:
                        header_values.pop()
                    if header_values != headers:
                        raise ValueError('Original/review header mismatch: ' + identity + '/' + sheet)
                    cursors[sheet] = review.execute('SELECT source_row,cells_json FROM source_rows WHERE source_id=? AND sheet_number=? ORDER BY source_row', (identity, index))
                    next_rows[sheet] = cursors[sheet].fetchone()
                    seen[sheet] = 0
                    continue
                saved = next_rows[sheet]
                if saved is None or saved[0] != number or json.loads(saved[1]) != values:
                    raise ValueError('Original/review cell mismatch: ' + identity + '/' + sheet + '/' + str(number))
                count += 1
                seen[sheet] += 1
                next_rows[sheet] = cursors[sheet].fetchone()
                if count % 1000 == 0:
                    status(root, state='validating_original_cells', source=identity, current_source_rows=count)
                    if not resources_ok(root):
                        raise RuntimeError('Waiting for disk/RAM reserve')
            if any(next_rows.values()) or any(seen.get(name, 0) != expected_rows for name, (_, _, expected_rows) in sheets.items()):
                raise ValueError('Original/review row counts differ: ' + identity)
            db.execute('INSERT INTO source_validations VALUES (?,?,?,?)',
                       (review_hash, identity, count, datetime.now(timezone.utc).isoformat()))
            db.commit()
            status(root, state='validating_original_cells', source=identity, source_completed=True, current_source_rows=count)


def run(root):
    root.mkdir(parents=True, exist_ok=True)
    (root / 'queue').mkdir(exist_ok=True)
    with closing(connect(root)) as db:
        for descriptor in sorted((root / 'queue').glob('*.json')):
            job = json.loads(descriptor.read_text(encoding='utf-8'))
            name, expected = job['package'], job['sha256']
            suffix = '.pdf' if job.get('kind') in ('pdf_ocr', 'pdf_text') else '.zip'
            if Path(name).name != name or not name.endswith(suffix) or not re.fullmatch('[a-f0-9]{64}', expected):
                raise ValueError('Invalid queue descriptor')
            package = root / 'packages' / name
            job_key = hashlib.sha256(json.dumps({'original':expected,'pages':job.get('pages')},sort_keys=True).encode()).hexdigest() if job.get('kind') == 'pdf_ocr' else expected
            db.execute('INSERT OR IGNORE INTO jobs(sha256,package,status) VALUES (?,?,?)', (job_key, name, 'pending'))
            db.commit()
            state, retry = db.execute('SELECT status,retry_after FROM jobs WHERE sha256=?', (job_key,)).fetchone()
            if state == 'complete' or retry > clock.time():
                continue
            if not resources_ok(root):
                status(root, state='waiting_for_resources')
                return
            db.execute('UPDATE jobs SET status=?,attempts=attempts+1,error=NULL WHERE sha256=?', ('running', job_key))
            db.commit()
            status(root, state='verifying_package', package=name)
            try:
                kind = job.get('kind', 'package')
                if kind == 'package':
                    process_package(root, package, expected, db)
                elif kind == 'validate_1991':
                    validate_1991(root, package, expected, job, db)
                elif kind == 'pdf_ocr':
                    from ocr_civic_pdf_pages import extract as ocr_extract
                    ocr_extract(root, package, expected, job, digest, resources_ok, status)
                elif kind == 'pdf_text':
                    from extract_dchb_pdf import extract as text_extract
                    text_extract(package, expected, job['source_url'], root / 'source-evidence' / ('pdf-' + expected))
                else:
                    raise ValueError('Unsupported queue job kind')
                db.execute('UPDATE jobs SET status=?,completed_at=?,retry_after=0 WHERE sha256=?',
                           ('complete', datetime.now(timezone.utc).isoformat(), job_key))
            except ResourceWait:
                db.rollback()
                db.execute('UPDATE jobs SET status=?,error=NULL,retry_after=0 WHERE sha256=?', ('pending', job_key))
                db.commit()
                status(root, state='waiting_for_resources', package=name,
                       note='Preserved page receipts resume at the next resource check.')
                return
            except Exception as error:
                db.rollback()
                db.execute('UPDATE jobs SET status=?,error=?,retry_after=? WHERE sha256=?',
                           ('error', str(error)[:1000], clock.time() + 1800, job_key))
            db.commit()
        jobs = dict(db.execute('SELECT status,count(*) FROM jobs GROUP BY status'))
        sources, rows = db.execute("SELECT count(*),coalesce(sum(row_count),0) FROM workbooks WHERE status='complete'").fetchone()
        validated, checked_rows = db.execute('SELECT count(*),coalesce(sum(rows_checked),0) FROM source_validations').fetchone()
        status(root, state='needs_attention' if jobs.get('error') else 'waiting_for_queue',
               jobs=jobs, completed_unique_workbooks=sources, extracted_raw_rows=rows,
               validated_1991_sources=validated, validated_1991_rows=checked_rows,
               errors=[{'package':p, 'error':e} for p,e in db.execute(
                   "SELECT package,error FROM jobs WHERE status='error' LIMIT 10")],
               note='Raw historical source rows include headers and notes; geography and population review pending.')


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--root', type=Path, required=True)
    args = parser.parse_args()
    root = args.root.resolve()
    # Staging must be outside any live app checkout.
    if 'app' in root.parts or 'application' in root.parts:
        raise ValueError('Choose an isolated worker directory outside the live application')
    root.mkdir(parents=True, exist_ok=True)
    import fcntl
    with (root / 'worker.lock').open('w') as lock:
        try:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            return
        from civic_resource_guard import admission
        gate = admission(root) if (root / 'shared-resource-gate').exists() else nullcontext((True, 'legacy'))
        with gate as (allowed, reason):
            if not allowed:
                status(root, state='waiting_for_resources', reason=reason)
                return
            if (root / 'catalogues.json').exists():
                from civic_queue_feeder import feed
                feed(root)
            run(root)
            if (root / 'catalogues.json').exists():
                feed(root, download_limit=0)


if __name__ == '__main__':
    main()
