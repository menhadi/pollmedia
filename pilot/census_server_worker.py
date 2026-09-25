"""Resumable raw Census workbook extraction in an isolated server review area.

Queue files contain a local package filename and its expected SHA-256. The
package format is produced by export_census_package.py. No live DB access.
"""
import argparse
from contextlib import closing
from datetime import date, datetime, time, timedelta, timezone
import hashlib
import json
from pathlib import Path
import re
import shutil
import sqlite3
import time as clock
import zipfile


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
        if available < 1536 * 1024**2:
            return False
    return True


def value_json(value):
    if isinstance(value, (datetime, date, time)):
        return value.isoformat()
    if isinstance(value, timedelta):
        return str(value)
    raise TypeError(type(value).__name__)


def workbook_rows(path):
    if path.suffix == '.xlsx':
        import openpyxl
        book = openpyxl.load_workbook(path, read_only=True, data_only=False)
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
            candidates = [key + ext for ext in ('.xls', '.xlsx') if key + ext in names]
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
            if Path(name).name != name or not name.endswith('.zip') or not re.fullmatch('[a-f0-9]{64}', expected):
                raise ValueError('Invalid queue descriptor')
            package = root / 'packages' / name
            db.execute('INSERT OR IGNORE INTO jobs(sha256,package,status) VALUES (?,?,?)', (expected, name, 'pending'))
            db.commit()
            state, retry = db.execute('SELECT status,retry_after FROM jobs WHERE sha256=?', (expected,)).fetchone()
            if state == 'complete' or retry > clock.time():
                continue
            if not resources_ok(root):
                status(root, state='waiting_for_resources')
                return
            db.execute('UPDATE jobs SET status=?,attempts=attempts+1,error=NULL WHERE sha256=?', ('running', expected))
            db.commit()
            status(root, state='verifying_package', package=name)
            try:
                kind = job.get('kind', 'package')
                if kind == 'package':
                    process_package(root, package, expected, db)
                elif kind == 'validate_1991':
                    validate_1991(root, package, expected, job, db)
                else:
                    raise ValueError('Unsupported queue job kind')
                db.execute('UPDATE jobs SET status=?,completed_at=?,retry_after=0 WHERE sha256=?',
                           ('complete', datetime.now(timezone.utc).isoformat(), expected))
            except Exception as error:
                db.rollback()
                db.execute('UPDATE jobs SET status=?,error=?,retry_after=? WHERE sha256=?',
                           ('error', str(error)[:1000], clock.time() + 1800, expected))
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
        run(root)


if __name__ == '__main__':
    main()
