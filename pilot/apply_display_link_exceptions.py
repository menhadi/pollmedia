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
    metadata = json.loads(database.execute('SELECT json FROM metadata').fetchone()[0])

    applied = []
    for entry in payload['entries']:
        layer = database.execute('SELECT file,sha256 FROM layers WHERE file=?', (entry['file'],)).fetchone()
        if not layer:
            raise SystemExit(f"layer not tracked: {entry['file']}")
        file, layer_sha = layer
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
                        'source_code': str(source.get('source_code')), 'source_name': source.get('name')})

    metadata['explicit_crosswalks'] = applied
    metadata['explicit_crosswalk_count'] = len(applied)
    metadata['explicit_crosswalk_policy'] = payload.get('policy')
    metadata['total_display_shapes'] = database.execute('SELECT count(*) FROM shapes').fetchone()[0]
    database.execute('DELETE FROM metadata')
    database.execute('INSERT INTO metadata VALUES (?)', (json.dumps(metadata),))
    database.commit()
    database.close()

    return {'applied': applied, 'total_display_shapes': metadata['total_display_shapes']}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('application', type=Path)
    parser.add_argument('exceptions', type=Path)
    arguments = parser.parse_args()
    print(json.dumps(apply(arguments.application, arguments.exceptions), indent=2))
