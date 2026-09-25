"""Package preserved LGD originals and pilot provenance for isolated raw review."""
import argparse
import hashlib
import json
from pathlib import Path
import zipfile


def export(root, destination):
    items = [
        ('lgd-up-20260916', 'pilot/raw/lgd-up-2026-09-16.zip',
         'application/database/fixtures/pilibhit-lgd.json', 'Uttar Pradesh administrative export; pilot extraction covers Pilibhit only'),
        ('lgd-pilibhit-electoral-20260916', 'pilot/raw/lgd-pilibhit-electoral-2026-09-16.xlsx',
         'application/database/fixtures/pilibhit-lgd-electoral.json', 'Pilibhit parliamentary constituency export; not a national electoral crosswalk'),
    ]
    sources = []
    with zipfile.ZipFile(destination, 'x', zipfile.ZIP_DEFLATED) as archive:
        for key, original_path, extracted_path, scope in items:
            original = root / original_path
            evidence = (root / extracted_path).read_bytes()
            metadata = json.loads(evidence)
            original_hash = hashlib.sha256(original.read_bytes()).hexdigest()
            if metadata['sha256'] != original_hash:
                raise ValueError('Preserved LGD original checksum differs: ' + key)
            archive.write(original, key + original.suffix)
            archive.writestr(key + '.json', evidence)
            sources.append({'key': key, 'source_url': metadata['url'], 'url_scope': 'collection',
                            'sha256': original_hash, 'extracted_sha256': hashlib.sha256(evidence).hexdigest(),
                            'checked_on': metadata['checked_on'], 'options': {'scope_note': scope,
                            'code_policy': 'Preserve source codes as strings, including leading zeroes. Historical Census/LGD identities require dated crosswalk review.'}})
        archive.writestr('manifest.json', json.dumps({'version': 1, 'sources': sources}, indent=2))
    result = {'package': destination.name, 'sha256': hashlib.sha256(destination.read_bytes()).hexdigest()}
    destination.with_suffix('.sha256').write_text(result['sha256'] + '  ' + destination.name + '\n', encoding='ascii')
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('destination', type=Path)
    args = parser.parse_args()
    print(json.dumps(export(Path(__file__).resolve().parents[1], args.destination)))
