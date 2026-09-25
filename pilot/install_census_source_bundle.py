"""Verify a prepared Census source-table bundle and install it atomically.

This installs source evidence only. It does not publish normalized Census
statistics, modify PostgreSQL, or replace an existing source-table directory.
"""

import argparse
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import shutil
import stat
import tempfile
import zipfile


PREFIX = 'application/storage/app/private/census-source-tables/'
IDENTITY = re.compile(r'\d{4}-\d+-[a-f0-9]{16}')


def digest(stream):
    value = hashlib.sha256()
    for block in iter(lambda: stream.read(1024 * 1024), b''):
        value.update(block)
    return value.hexdigest()


def verify(package, expected_sha256):
    with package.open('rb') as stream:
        if digest(stream) != expected_sha256:
            raise ValueError('Bundle SHA-256 mismatch')
    with zipfile.ZipFile(package) as archive:
        infos = archive.infolist()
        if len({item.filename for item in infos}) != len(infos):
            raise ValueError('Duplicate ZIP member')
        manifest = json.loads(archive.read('manifest.json'))
        files = manifest.get('files')
        if not isinstance(files, dict) or not files:
            raise ValueError('Bundle has no file checksums')
        if set(item.filename for item in infos) != set(files) | {'manifest.json'}:
            raise ValueError('Bundle members differ from manifest')
        for item in infos:
            name = item.filename
            if name == 'manifest.json':
                continue
            relative = name.removeprefix(PREFIX)
            parts = PurePosixPath(relative).parts
            if (not name.startswith(PREFIX) or not parts or any(part in ('', '.', '..') for part in parts)
                    or '\\' in name or item.is_dir() or stat.S_ISLNK(item.external_attr >> 16)
                    or not re.fullmatch(r'[a-f0-9]{64}', str(files[name]))):
                raise ValueError('Unsafe Census bundle member: ' + name)
            with archive.open(item) as stream:
                if digest(stream) != files[name]:
                    raise ValueError('Bundle member SHA-256 mismatch: ' + name)
        index = json.loads(archive.read(PREFIX + 'index.json'))
        sources = index.get('sources')
        pending = index.get('pending')
        if (not isinstance(sources, list) or not isinstance(pending, list)
                or len(sources) != manifest.get('workbooks') or len(pending) != manifest.get('pending')):
            raise ValueError('Census source counts differ from bundle manifest')
        for source in sources:
            identity = source.get('id', '')
            if not IDENTITY.fullmatch(identity):
                raise ValueError('Invalid Census source identity')
            for filename in ('manifest.json', 'pages.jsonl'):
                if PREFIX + identity + '/' + filename not in files:
                    raise ValueError('Census source is missing prepared evidence')
        return {'workbooks': len(sources), 'pending': len(pending), 'files': len(files)}


def install(package, destination):
    if destination.name != 'census-source-tables' or destination.parent.name != 'private':
        raise ValueError('Destination must be the private Census source-table directory')
    if destination.exists():
        raise FileExistsError('Census source tables already exist; refusing to replace them')
    temporary = Path(tempfile.mkdtemp(prefix='.census-source-tables-', dir=destination.parent))
    try:
        with zipfile.ZipFile(package) as archive:
            for name in archive.namelist():
                if name == 'manifest.json':
                    continue
                target = temporary / name.removeprefix(PREFIX)
                target.parent.mkdir(parents=True, exist_ok=True)
                with archive.open(name) as source, target.open('wb') as output:
                    shutil.copyfileobj(source, output, length=1024 * 1024)
        if destination.exists():
            raise FileExistsError('Census source tables appeared during installation')
        os.rename(temporary, destination)
    finally:
        if temporary.exists():
            shutil.rmtree(temporary)


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('package', type=Path)
    parser.add_argument('--sha256', required=True)
    parser.add_argument('--destination', type=Path)
    args = parser.parse_args()
    if not re.fullmatch(r'[a-f0-9]{64}', args.sha256):
        parser.error('--sha256 must be a lowercase SHA-256 digest')
    result = verify(args.package, args.sha256)
    if args.destination:
        install(args.package, args.destination)
        result['installed'] = str(args.destination)
    print(json.dumps(result, sort_keys=True))


if __name__ == '__main__':
    main()
