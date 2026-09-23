"""Package exact archived election JSON bytes for checksum-verified DB import."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import zipfile


CATEGORIES = ('election-archive', 'election-by-elections')
PATH_RE = re.compile(r'^(?:election-archive|election-by-elections)/[A-Za-z0-9_./-]+\.json$')


def package(root: Path, output: Path, category: str, bucket: int, buckets: int) -> dict:
    if category not in CATEGORIES or not 0 <= bucket < buckets <= 64:
        raise ValueError('Choose a category and bucket within 1–64 total buckets')
    files = []
    for path in (root / category).rglob('*.json'):
        relative = path.relative_to(root).as_posix()
        if (not PATH_RE.fullmatch(relative) or '..' in Path(relative).parts or path.is_symlink()
                or not path.resolve().is_relative_to(root.resolve())):
            raise ValueError(f'Unsafe archived JSON path: {relative}')
        if hashlib.sha256(relative.encode('utf-8')).digest()[0] % buckets == bucket:
            files.append((relative, path))
    files.sort()
    if not files:
        raise ValueError('Selected bucket contains no archived JSON')

    output.parent.mkdir(parents=True, exist_ok=True)
    temporary = output.with_suffix(output.suffix + '.partial')
    if output.exists() or temporary.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(f'Archive package already exists: {output}')
    entries = []
    try:
        with zipfile.ZipFile(temporary, 'w', compression=zipfile.ZIP_DEFLATED,
                             compresslevel=6, allowZip64=True) as archive:
            for relative, path in files:
                before = path.stat()
                digest = hashlib.sha256()
                size = 0
                with path.open('rb') as source, archive.open(relative, 'w') as target:
                    while chunk := source.read(1024 * 1024):
                        target.write(chunk)
                        digest.update(chunk)
                        size += len(chunk)
                after = path.stat()
                if size != before.st_size or after.st_size != before.st_size or after.st_mtime_ns != before.st_mtime_ns:
                    raise RuntimeError(f'Archive source changed during packaging: {relative}')
                entries.append({'path': relative, 'sha256': digest.hexdigest(), 'bytes': size})
            manifest = {'version': 1, 'category': category, 'bucket': bucket,
                        'buckets': buckets, 'files': entries}
            archive.writestr('manifest.json', json.dumps(manifest, ensure_ascii=False,
                                                         sort_keys=True, separators=(',', ':')))
        temporary.replace(output)
        with output.open('rb') as stream:
            checksum = hashlib.file_digest(stream, 'sha256').hexdigest()
        output.with_suffix('.sha256').write_text(checksum + '  ' + output.name + '\n', encoding='ascii')
    finally:
        temporary.unlink(missing_ok=True)
    return {'category': category, 'bucket': bucket, 'buckets': buckets,
            'files': len(entries), 'source_bytes': sum(entry['bytes'] for entry in entries),
            'package_bytes': output.stat().st_size, 'sha256': checksum}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('output', type=Path)
    parser.add_argument('--root', type=Path, default=Path('application/storage/app/private'))
    parser.add_argument('--category', required=True, choices=CATEGORIES)
    parser.add_argument('--bucket', type=int, required=True)
    parser.add_argument('--buckets', type=int, default=8)
    args = parser.parse_args()
    print(json.dumps(package(args.root, args.output, args.category, args.bucket, args.buckets)))
