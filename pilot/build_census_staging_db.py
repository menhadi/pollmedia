"""Build an isolated SQLite review database from checksummed Census source pages.

The database preserves historical source cells and provenance. It does not
normalize geography, combine population groups, or publish live statistics.
"""

import argparse
import hashlib
import json
from pathlib import Path
import re
import sqlite3


IDENTITY = re.compile(r'\d{4}-\d+-[a-f0-9]{16}')


def sha256(path):
    digest = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(block)
    return digest.hexdigest()


def build(root, output):
    if output.exists():
        raise FileExistsError('Staging database already exists; refusing to replace it')
    index = json.loads((root / 'index.json').read_text(encoding='utf-8'))
    if not isinstance(index.get('sources'), list) or not isinstance(index.get('pending'), list):
        raise ValueError('Invalid Census source index')
    connection = sqlite3.connect(output)
    try:
        connection.executescript('''
            PRAGMA foreign_keys=ON;
            CREATE TABLE sources (
                id TEXT PRIMARY KEY, year INTEGER NOT NULL, population_group TEXT NOT NULL,
                area_as_recorded TEXT NOT NULL, source_url TEXT NOT NULL,
                source_sha256 TEXT NOT NULL, extraction_sha256 TEXT NOT NULL,
                manifest_sha256 TEXT NOT NULL, scope_note TEXT NOT NULL
            );
            CREATE TABLE sheets (
                source_id TEXT NOT NULL REFERENCES sources(id), number INTEGER NOT NULL,
                name TEXT NOT NULL, headers_json TEXT NOT NULL, expected_rows INTEGER NOT NULL,
                PRIMARY KEY (source_id, number)
            );
            CREATE TABLE source_rows (
                source_id TEXT NOT NULL, sheet_number INTEGER NOT NULL,
                source_row INTEGER NOT NULL, cells_json TEXT NOT NULL, flags_json TEXT NOT NULL,
                formula_columns_json TEXT NOT NULL, error_columns_json TEXT NOT NULL,
                page_sha256 TEXT NOT NULL,
                PRIMARY KEY (source_id, sheet_number, source_row),
                FOREIGN KEY (source_id, sheet_number) REFERENCES sheets(source_id, number)
            );
        ''')
        total_rows = 0
        for entry in index['sources']:
            identity = entry.get('id', '')
            if not IDENTITY.fullmatch(identity):
                raise ValueError('Invalid Census source identity')
            folder = root / identity
            metadata_path = folder / 'manifest.json'
            pages_path = folder / 'pages.jsonl'
            if sha256(metadata_path) != entry['manifest_sha256']:
                raise ValueError('Census source manifest checksum differs: ' + identity)
            metadata = json.loads(metadata_path.read_text(encoding='utf-8'))
            if metadata['id'] != identity or sha256(pages_path) != metadata['pages_sha256']:
                raise ValueError('Census source pages checksum differs: ' + identity)
            connection.execute('INSERT INTO sources VALUES (?,?,?,?,?,?,?,?,?)', (
                identity, metadata['year'], metadata['population_group'], metadata['area_as_recorded'],
                metadata['source_url'], metadata['source_sha256'], metadata['extraction_sha256'],
                entry['manifest_sha256'], metadata['scope_note']))
            with pages_path.open('rb') as stream:
                for number, sheet in enumerate(metadata['sheets']):
                    connection.execute('INSERT INTO sheets VALUES (?,?,?,?,?)', (
                        identity, number, sheet['name'], json.dumps(sheet['headers'], ensure_ascii=False),
                        sheet['row_count']))
                    count = 0
                    for page in sheet['pages']:
                        if not isinstance(page['offset'], int) or not 0 <= page['length'] <= 8 * 1024 * 1024:
                            raise ValueError('Invalid Census page offset or length')
                        stream.seek(page['offset'])
                        body = stream.read(page['length'])
                        if hashlib.sha256(body).hexdigest() != page['sha256']:
                            raise ValueError('Census page checksum differs: ' + identity)
                        rows = json.loads(body)
                        if not isinstance(rows, list) or len(rows) > 100:
                            raise ValueError('Invalid Census page rows')
                        connection.executemany('INSERT INTO source_rows VALUES (?,?,?,?,?,?,?,?)', [(
                            identity, number, row['source_row'],
                            json.dumps(row['cells'], ensure_ascii=False, separators=(',', ':')),
                            json.dumps(row.get('flags', []), ensure_ascii=False),
                            json.dumps(row.get('formula_columns', []), ensure_ascii=False),
                            json.dumps(row.get('error_columns', []), ensure_ascii=False),
                            page['sha256']) for row in rows])
                        count += len(rows)
                    if count != sheet['row_count']:
                        raise ValueError('Census sheet row count differs: ' + identity)
                    total_rows += count
            connection.commit()
        source_count = connection.execute('SELECT count(*) FROM sources').fetchone()[0]
        row_count = connection.execute('SELECT count(*) FROM source_rows').fetchone()[0]
        if source_count != len(index['sources']) or row_count != total_rows:
            raise ValueError('Census staging database count differs')
        integrity = connection.execute('PRAGMA integrity_check').fetchone()[0]
        if integrity != 'ok':
            raise ValueError('SQLite integrity check failed: ' + integrity)
        return {'sources': source_count, 'rows': row_count,
                'pending': len(index['pending']), 'integrity': integrity}
    except Exception:
        connection.close()
        output.unlink(missing_ok=True)
        raise
    finally:
        connection.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, required=True)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    print(json.dumps(build(args.root, args.output), sort_keys=True))


if __name__ == '__main__':
    main()
