"""Add verified source-table directories, switching only the index atomically."""
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import tempfile


def digest(path):
    h = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            h.update(block)
    return h.hexdigest()


def install(source, destination, backup, expected_index):
    source, destination, backup = map(Path, (source, destination, backup))
    lock = destination / '.add-source-tables.lock'
    fd = os.open(lock, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    temporary = None
    try:
        index = destination / 'index.json'
        if digest(index) != expected_index:
            raise ValueError('Live index changed')
        old = json.loads(index.read_text(encoding='utf-8'))
        new = json.loads((source / 'index.json').read_text(encoding='utf-8'))
        ids = {item['id'] for item in old['sources']}
        for entry in new['sources']:
            identity = entry['id']
            if not re.fullmatch(r'\d{4}-\d+-[a-f0-9]{16}', identity):
                raise ValueError('Unsafe identity')
            if identity in ids or (destination / identity).exists():
                raise ValueError('Source collision')
            ids.add(identity)
            folder = source / identity
            if folder.is_symlink() or any((folder / n).is_symlink() for n in ('manifest.json', 'pages.jsonl')):
                raise ValueError('Symlink source rejected')
            if digest(folder / 'manifest.json') != entry['manifest_sha256']:
                raise ValueError('Manifest checksum differs')
            metadata = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
            if metadata['id'] != identity or digest(folder / 'pages.jsonl') != metadata['pages_sha256']:
                raise ValueError('Page checksum or identity differs')
        with backup.open('xb') as output:
            output.write(index.read_bytes())
            output.flush()
            os.fsync(output.fileno())
        temporary = Path(tempfile.mkdtemp(prefix='.add-census-', dir=destination))
        for entry in new['sources']:
            identity = entry['id']
            shutil.copytree(source / identity, temporary / identity)
            for name in ('manifest.json', 'pages.jsonl'):
                if digest(temporary / identity / name) != digest(source / identity / name):
                    raise ValueError('Copied evidence checksum differs')
        # Unreferenced new directories after interruption are retained for recovery.
        for entry in new['sources']:
            identity = entry['id']
            if (destination / identity).exists():
                raise ValueError('Source appeared during installation')
            os.rename(temporary / identity, destination / identity)
        if digest(index) != expected_index:
            raise ValueError('Live index changed before switch')
        merged = dict(old, sources=old['sources'] + new['sources'])
        pending_index = temporary / 'index.json'
        with pending_index.open('w', encoding='utf-8') as output:
            json.dump(merged, output, ensure_ascii=False, indent=2)
            output.flush()
            os.fsync(output.fileno())
        os.replace(pending_index, index)
        return {'old_count': len(old['sources']), 'new_count': len(merged['sources']),
                'added': [s['id'] for s in new['sources']], 'backup': str(backup),
                'before_sha256': expected_index, 'after_sha256': digest(index)}
    finally:
        if temporary is not None:
            shutil.rmtree(temporary)
        os.close(fd)
        lock.unlink()
