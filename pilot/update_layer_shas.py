#!/usr/bin/env python3
"""Synchronise layer hashes after appending features to existing district files.

The geometry file name and feature order are unchanged, so recorded links stay
valid. The hash recorded for the layer must change in every store that carries
it, including the display-links integrity anchor.
"""
import argparse
import hashlib
import json
import sqlite3
from pathlib import Path


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('application', type=Path)
    parser.add_argument('manifests', nargs='+', type=Path)
    args = parser.parse_args()

    app = args.application
    changed = {}
    for manifest_path in args.manifests:
        manifest = json.loads(manifest_path.read_text())
        for entry in manifest['files']:
            changed[entry['file']] = entry['sha256']

    links_path = app / 'storage/app/electoral-villages/links.sqlite'
    supplement_path = app / 'storage/app/electoral-villages/display-links.sqlite'
    links = sqlite3.connect(links_path)
    supplement = sqlite3.connect(supplement_path)

    for file, sha in changed.items():
        links.execute('UPDATE layers SET sha256=? WHERE file=?', (sha, file))
        supplement.execute('UPDATE layers SET sha256=? WHERE file=?', (sha, file))
        supplement.execute('UPDATE shapes SET sha256=? WHERE file=?', (sha, file))
    links.commit()
    supplement.commit()

    links_hash = hashlib.sha256(links_path.read_bytes()).hexdigest()
    metadata = json.loads(supplement.execute('SELECT json FROM metadata').fetchone()[0])
    metadata['original_links_sha256'] = links_hash
    supplement.execute('DELETE FROM metadata')
    supplement.execute('INSERT INTO metadata VALUES (?)', (json.dumps(metadata),))
    supplement.commit()
    links.close()
    supplement.close()

    print(json.dumps({'files': len(changed), 'links_sha256': links_hash[:16] + '…'}, indent=1))


if __name__ == '__main__':
    main()
