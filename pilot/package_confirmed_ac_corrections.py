"""Package the seven source-verified AC gap repairs as guarded JSON revisions."""
import hashlib
import json
from pathlib import Path

from preserve_archive_json import package
from restore_confirmed_ac_gaps import LEGACY, TAMIL_1991, UNCONTESTED

OLD = {
    '482b0cfa0689d697b8830cd0': '81b574f5b1fb750d8a45b305512b7f2c9ea49fb04743fd08ec35c5a5430f00d2',
    'a68e5ff94ea8b19adbf5378b': 'aaacf4029a462154d9ca61e14391b7b4a93e07499ecf6cda8518d428f0b537a0',
    'affdd40634235082132e418b': '259533abf376e0b5e0807087c586b59713be0f3f6bf2f57bf73a47136729c800',
    'f0d9e36a60bcef19312cabc4': '1d9c09b4e9824cf0297bb5bdd927f88edc5e222a0abe5745fe7123a587354dce',
    '8bea140010fef922a65fa3e7': 'd759807fad5bc3268ad2b20637aab07b49fb420359b1ac3129e16a457e58e2a2',
    'b9dc2765d7ca0d1c746693ea': 'cedaba7814ce0e13d87f640be42720f514398e117b259ace100d3c6a70192f36',
    '60d8a031055599ff3b89eb78': '06e680532071b84b600aaa765c6e8a11624904765b010c006fddb0f48e79becc',
}


def packages(root):
    private = root / 'application/storage/app/private'
    output = root / 'exports'
    results = []
    for edition_id in dict.fromkeys([*LEGACY, TAMIL_1991, *UNCONTESTED]):
        folder = private / 'election-archive' / edition_id
        old_sha256 = OLD[edition_id]
        previous = folder / ('extraction-' + old_sha256 + '.json')
        with previous.open('rb') as stream:
            if hashlib.file_digest(stream, 'sha256').hexdigest() != old_sha256:
                raise ValueError('Prior extraction checksum differs: ' + edition_id)
        current = folder / 'extraction.json'
        for label, path, revision in [('snapshot', previous, False), ('correction', current, True)]:
            relative = path.relative_to(private).as_posix()
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            archive = output / f'pollmedia-ac-{edition_id}-{label}-20260930.zip'
            if archive.exists():
                import zipfile
                with zipfile.ZipFile(archive) as existing:
                    manifest = json.loads(existing.read('manifest.json'))
                    if ([item['path'] for item in manifest['files']] != [relative]
                            or hashlib.sha256(existing.read(relative)).hexdigest() != manifest['files'][0]['sha256']):
                        raise ValueError('Existing package differs: ' + str(archive))
                with archive.open('rb') as stream:
                    digest = hashlib.file_digest(stream, 'sha256').hexdigest()
                summary = {'category': 'election-archive', 'bucket': bucket, 'buckets': 8,
                           'files': 1, 'package_bytes': archive.stat().st_size, 'sha256': digest}
            else:
                summary = package(private, archive, 'election-archive', bucket, 8, [relative],
                                  old_sha256 if revision else None,
                                  previous.relative_to(private).as_posix() if revision else None)
            results.append({'edition_id': edition_id, 'label': label, 'path': archive.as_posix(), **summary})
    return results


if __name__ == '__main__':
    print(json.dumps(packages(Path(__file__).resolve().parents[1]), indent=2))
