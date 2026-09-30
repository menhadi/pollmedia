"""Package exact archived election JSON bytes for checksum-verified DB import."""

import argparse
import hashlib
import json
from pathlib import Path
import re
import zipfile


CATEGORIES = ('election-archive', 'election-by-elections')
PATH_RE = re.compile(r'^(?:election-archive|election-by-elections)/[A-Za-z0-9_./-]+\.json$')


def package(root: Path, output: Path, category: str, bucket: int, buckets: int,
            selected_paths: list[str] | None = None, replaces_sha256: str | None = None,
            previous_path: str | None = None) -> dict:
    if category not in CATEGORIES or not 0 <= bucket < buckets <= 64:
        raise ValueError('Choose a category and bucket within 1–64 total buckets')
    files = []
    if selected_paths is not None and not selected_paths:
        raise ValueError('Choose at least one exact archive JSON path')
    if (replaces_sha256 is None) != (previous_path is None):
        raise ValueError('A revision needs both the previous checksum and snapshot path')
    if replaces_sha256 is not None:
        if not selected_paths or len(selected_paths) != 1 or not re.fullmatch(r'[a-f0-9]{64}', replaces_sha256):
            raise ValueError('A revision needs one exact path and a SHA-256 checksum')
        if (not PATH_RE.fullmatch(previous_path) or previous_path == selected_paths[0]
                or not previous_path.startswith(category + '/')
                or not previous_path.endswith('-' + replaces_sha256 + '.json')):
            raise ValueError('The previous snapshot path must name its exact SHA-256')
        snapshot = root / previous_path
        if not snapshot.is_file() or snapshot.is_symlink():
            raise ValueError('The previous JSON snapshot is missing')
        with snapshot.open('rb') as stream:
            if hashlib.file_digest(stream, 'sha256').hexdigest() != replaces_sha256:
                raise ValueError('The previous JSON snapshot checksum differs')
    candidates = ((root / relative for relative in selected_paths) if selected_paths is not None
                  else (root / category).rglob('*.json'))
    for path in candidates:
        relative = path.relative_to(root).as_posix()
        if (not PATH_RE.fullmatch(relative) or '..' in Path(relative).parts or path.is_symlink()
                or not path.resolve().is_relative_to(root.resolve()) or not path.is_file()
                or not relative.startswith(category + '/')):
            raise ValueError(f'Unsafe archived JSON path: {relative}')
        assigned_bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % buckets
        if selected_paths is not None and assigned_bucket != bucket:
            raise ValueError(f'Archived JSON path belongs to bucket {assigned_bucket}: {relative}')
        if assigned_bucket == bucket:
            files.append((relative, path))
    files.sort()
    if len({relative for relative, _ in files}) != len(files):
        raise ValueError('Duplicate archive JSON path')
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
                entry = {'path': relative, 'sha256': digest.hexdigest(), 'bytes': size}
                if replaces_sha256 is not None:
                    entry.update({'replaces_sha256': replaces_sha256, 'previous_path': previous_path})
                entries.append(entry)
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
    parser.add_argument('--path', action='append', dest='selected_paths',
                        help='Package only this exact root-relative JSON path; repeat as needed')
    parser.add_argument('--replaces-sha256', help='Existing checksum for one guarded revision')
    parser.add_argument('--previous-path', help='Already preserved versioned JSON snapshot path')
    args = parser.parse_args()
    print(json.dumps(package(args.root, args.output, args.category, args.bucket, args.buckets,
                             args.selected_paths, args.replaces_sha256, args.previous_path)))
