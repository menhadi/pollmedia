#!/usr/bin/env python3
"""Apply explicitly authorised display-identity links to display-links.sqlite.

Unlike build_village_display_links.py, this tool does not infer anything. It
applies a curated exception list where an official record justifies attaching a
retained source polygon to an official place code, and records the full evidence
beside the link. Re-running it is idempotent.
"""
import argparse
import hashlib
import json
import sqlite3
from pathlib import Path


def sha256(path: Path) -> str:
    return hashlib.sha256(path.read_bytes()).hexdigest()


def apply(application: Path, exceptions: Path) -> dict:
    payload = json.loads(exceptions.read_text(encoding='utf-8'))
    database_path = application / 'storage/app/electoral-villages/display-links.sqlite'
    if not database_path.is_file():
        raise SystemExit(f'missing {database_path}')
    database = sqlite3.connect(database_path)
    main = sqlite3.connect(database_path.parent / 'links.sqlite')
    metadata = json.loads(database.execute('SELECT json FROM metadata').fetchone()[0])

    applied = []
    skipped = []
    for entry in payload['entries']:
        layer = main.execute('SELECT file,sha256 FROM layers WHERE file=?', (entry['file'],)).fetchone() \
            or database.execute('SELECT file,sha256 FROM layers WHERE file=?', (entry['file'],)).fetchone()
        if not layer:
            raise SystemExit(f"layer not tracked: {entry['file']}")
        file, layer_sha = layer
        existing = database.execute('SELECT file,feature_index FROM shapes WHERE lgd=? AND NOT (file=? AND feature_index=?)',
                                    (str(entry['lgd']), file, int(entry['feature_index']))).fetchone()
        if existing:
            skipped.append({'lgd': str(entry['lgd']), 'linked_file': existing[0], 'linked_feature': existing[1]})
            continue
        geometry = application / 'storage/app/india-map/snapshot' / file
        if not geometry.is_file():
            geometry = application / 'public' / file
        if sha256(geometry) != layer_sha:
            raise SystemExit(f'geometry hash differs from tracked layer: {file}')

        features = json.loads(geometry.read_text(encoding='utf-8'))['features']
        index = int(entry['feature_index'])
        source = features[index]['properties']
        if str(source.get('source_code')) != str(entry['source_code']):
            raise SystemExit(f"feature {index} source code {source.get('source_code')} != {entry['source_code']}")

        database.execute('INSERT OR REPLACE INTO shapes VALUES (?,?,?,?,?,?)',
                         (file, index, str(entry['lgd']), str(entry['state']), entry['basis'], layer_sha))
        proof = dict(entry['evidence'])
        proof.update({'file': file, 'feature_index': index, 'layer_sha256': layer_sha,
                      'source_code': str(source.get('source_code')), 'source_name': source.get('name'),
                      'source_category': source.get('category'), 'lgd_code': str(entry['lgd']),
                      'state_lgd_code': str(entry['state']), 'basis': entry['basis'],
                      'authorisation': payload.get('authorisation')})
        database.execute('INSERT OR REPLACE INTO evidence VALUES (?,?,?)', (file, index, json.dumps(proof)))

        applied.append({'file': file, 'feature_index': index, 'lgd': str(entry['lgd']),
                        'state': str(entry['state']),
                        'source_code': str(source.get('source_code')), 'source_name': source.get('name')})

    merged = {(row['file'], row['feature_index']): row for row in metadata.get('explicit_crosswalks', [])}
    for row in applied:
        merged[(row['file'], row['feature_index'])] = row
    metadata['explicit_crosswalks'] = sorted(merged.values(), key=lambda row: (row['file'], row['feature_index']))
    metadata['explicit_crosswalk_count'] = len(metadata['explicit_crosswalks'])
    metadata['explicit_crosswalk_policy'] = payload.get('policy')
    metadata['total_display_shapes'] = database.execute('SELECT count(*) FROM shapes').fetchone()[0]
    database.execute('DELETE FROM metadata')
    database.execute('INSERT INTO metadata VALUES (?)', (json.dumps(metadata),))
    database.commit()
    database.close()

    return {'applied': len(applied), 'skipped': skipped, 'total_display_shapes': metadata['total_display_shapes'],
            'explicit_crosswalk_count': metadata['explicit_crosswalk_count']}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('application', type=Path)
    parser.add_argument('exceptions', type=Path)
    arguments = parser.parse_args()
    print(json.dumps(apply(arguments.application, arguments.exceptions), indent=2))
