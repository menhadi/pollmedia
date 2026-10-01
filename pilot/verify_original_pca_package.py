"""Validate source-separated original PCA evidence without database writes."""
import hashlib
import json
import zipfile
from pathlib import PurePosixPath


def verify(path, expected_sha256):
    if hashlib.sha256(path.read_bytes()).hexdigest() != expected_sha256:
        raise ValueError('Package checksum mismatch')
    with zipfile.ZipFile(path) as archive:
        names = archive.namelist()
        if len(names) != len(set(names)):
            raise ValueError('Duplicate archive member')
        manifest = json.loads(archive.read('manifest.json'))
        if manifest.get('family') != 'historical-census-original-pca' or manifest.get('version') != 1:
            raise ValueError('Unsupported original PCA package')
        files = manifest['files']
        if set(names) != set(files) | {'manifest.json'}:
            raise ValueError('Unregistered archive member')
        for name, digest in files.items():
            member = PurePosixPath(name)
            if member.is_absolute() or '..' in member.parts or '\\' in name:
                raise ValueError('Unsafe archive member')
            if archive.getinfo(name).file_size > 50_000_000:
                raise ValueError('Oversized archive member')
            if hashlib.sha256(archive.read(name)).hexdigest() != digest:
                raise ValueError('Member checksum mismatch')
        original = manifest['original']
        if files.get(original) != manifest['original_sha256'] or not archive.read(original).startswith(b'%PDF-'):
            raise ValueError('Original PDF mismatch')
        if not manifest.get('boundary_basis') or not manifest.get('source_url', '').startswith('https://censusindia.gov.in/nada/index.php/catalog/'):
            raise ValueError('Missing original-source provenance')
        catalogue = manifest.get('catalogue')
        year = manifest.get('year')
        if not isinstance(catalogue, str) or not catalogue.isdigit() or type(year) is not int:
            raise ValueError('Invalid source catalogue or year')
        if manifest.get('source_key') != f'census-original-pca-{catalogue}-{year}':
            raise ValueError('Invalid source partition')
        if not manifest['source_url'].startswith(f'https://censusindia.gov.in/nada/index.php/catalog/{catalogue}/download/'):
            raise ValueError('Source URL belongs to another catalogue')
        audit = json.loads(archive.read('evidence/pca-mapping-audit-20261001T1901.json'))
        rows = audit['rows']
        if len(rows) != manifest['row_count']:
            raise ValueError('Row count mismatch')
        keys = set()
        for row in rows:
            identity = row['source_record_identity']
            expected_identity = f'{catalogue}:{year}:{row["original_serial"]}:{row["residence"]}'
            if identity != expected_identity or row.get('level') not in ['STATE', 'DISTRICT']:
                raise ValueError('Source identity or geography level mismatch')
            if row['record_key'] != hashlib.sha256(identity.encode()).hexdigest() or identity in keys:
                raise ValueError('Duplicate or invalid source identity')
            keys.add(identity)
            if not row['original_name'] or row['residence'] not in ['Total', 'Rural', 'Urban'] or not row['flags'] or not all(isinstance(flag, str) and flag.strip() for flag in row['flags']):
                raise ValueError('Missing original identity or notes')
            if any(type(value) is not int or value < 0 for value in row['values'].values()):
                raise ValueError('Invalid count')
            for total, male, female in [('TOT_P', 'TOT_M', 'TOT_F'), ('P_LIT', 'M_LIT', 'F_LIT'), ('TOT_WORK_P', 'TOT_WORK_M', 'TOT_WORK_F')]:
                values = row['values']
                if not all(field in values for field in [total, male, female]):
                    raise ValueError('Incomplete count mapping')
                if values[total] != values[male] + values[female] and not any('Source discrepancy' in flag and total in flag for flag in row['flags']):
                    raise ValueError('Unnoted source discrepancy')
        return manifest, rows
