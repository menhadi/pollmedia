"""Validate source-separated original PCA evidence without database writes."""
import hashlib
import json
import zipfile
import re
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
        if catalogue != '30750' or year != 1961:
            raise ValueError('Original PCA source adapter unavailable')
        if manifest.get('source_key') != f'census-original-pca-{catalogue}-{year}':
            raise ValueError('Invalid source partition')
        if not manifest['source_url'].startswith(f'https://censusindia.gov.in/nada/index.php/catalog/{catalogue}/download/'):
            raise ValueError('Source URL belongs to another catalogue')
        audit = json.loads(archive.read('evidence/pca-mapping-audit-20261001T1901.json'))
        rows = audit['rows']
        if len(rows) != manifest['row_count']:
            raise ValueError('Row count mismatch')
        additional = _additional_district_rows(archive, manifest)
        state_evidence = json.loads(archive.read('evidence/kerala-pca-state-verified-candidates-v2.json'))
        district_evidence = json.loads(archive.read('evidence/kerala-pca-cannanore-verified-candidates.json'))
        if any(e.get('year') != year or e.get('original_sha256') != manifest['original_sha256'] for e in [state_evidence, district_evidence]):
            raise ValueError('Supplemental evidence differs')
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
            candidate = additional.get(f'{row["original_serial"]}:{row["residence"]}') if row['level'] == 'DISTRICT' else None
            if candidate:
                if row['original_name'] != candidate['original_name'] or row['values'] != candidate['values']:
                    raise ValueError('District mapping differs from verified cells')
                if any(note not in row['flags'] for note in candidate['source_discrepancy_notes']):
                    raise ValueError('Source discrepancy note not preserved')
                row['values']['AREA_ACRES'] = candidate['area_acres_original']
            else:
                if row['level'] == 'STATE' and row['original_name'] != state_evidence['original_name']:
                    raise ValueError('State identity differs')
                supplement = state_evidence['rows'] if row['level'] == 'STATE' else district_evidence['rows']
                matches = [c for c in supplement if c['residence'] == row['residence'] and (row['level'] == 'STATE' or (c['original_serial'] == row['original_serial'] and c['original_name'] == row['original_name']))]
                if len(matches) != 1:
                    raise ValueError('Supplemental row identity differs')
                row['values']['OCCUPIED_HOUSES'] = matches[0]['occupied_residential_houses']
                row['values'].update({f'CULTIVATOR_{sex}': count for sex, count in zip(['P', 'M', 'F'], matches[0]['cultivators'])})
            for field, value in row['values'].items():
                if field == 'AREA_ACRES':
                    if not candidate or not isinstance(value, str) or not re.fullmatch(r'(0|[1-9][0-9]*)\.[0-9]{2}', value) or manifest.get('measure_units', {}).get(field) != 'acres (original source)':
                        raise ValueError('Acreage lacks exact source evidence or units')
                elif type(value) is not int or value < 0:
                    raise ValueError('Invalid count')
            for total, male, female in [('TOT_P', 'TOT_M', 'TOT_F'), ('P_LIT', 'M_LIT', 'F_LIT'), ('TOT_WORK_P', 'TOT_WORK_M', 'TOT_WORK_F')]:
                values = row['values']
                if not all(field in values for field in [total, male, female]):
                    raise ValueError('Incomplete count mapping')
                if values[total] != values[male] + values[female] and not any('Source discrepancy' in flag and total in flag for flag in row['flags']):
                    raise ValueError('Unnoted source discrepancy')
        _validate_reconciliations(rows)
        return manifest, rows


def _additional_district_rows(archive, manifest):
    adapters = {'4': ('trichur', 'TRICHUR DISTRICT', 182), '5': ('ernakulam', 'ERNAKULAM DISTRICT', 188),
                '6': ('kottayam', 'KOTTAYAM DISTRICT', 194), '7': ('alleppey', 'ALLEPPEY DISTRICT', 194),
                '8': ('quilon', 'QUILON DISTRICT', 200), '9': ('trivandrum', 'TRIVANDRUM DISTRICT', 206)}
    result = {}
    members = manifest.get('supplemental_district_evidence', {})
    if not isinstance(members, dict):
        raise ValueError('Invalid district evidence registry')
    for serial, member in members.items():
        adapter = adapters.get(serial)
        if not adapter or member not in [f'evidence/kerala-pca-{adapter[0]}-{part}-verified-candidates.json' for part in ['core', 'full']] or member not in manifest['files']:
            raise ValueError('Unsupported district evidence')
        evidence = json.loads(archive.read(member))
        pages = list(range(adapter[2], adapter[2] + (6 if '-full-' in member else 3)))
        if evidence.get('source_catalogue') != 30750 or evidence.get('year') != 1961 or evidence.get('original_sha256') != manifest['original_sha256'] or evidence.get('physical_pages') != pages or evidence.get('printed_pages') != [p - 16 for p in pages] or len(evidence.get('rows', [])) != 3:
            raise ValueError('District page provenance differs')
        for page in pages:
            digest = evidence.get('render_hashes', {}).get(str(page))
            if not digest or manifest['files'].get(f'evidence/kerala-1961-pca-{page}.png') != digest:
                raise ValueError('District rendering not preserved')
        for page, digest in evidence['render_hashes'].items():
            match = re.fullmatch(r'([0-9]+)(_high)?', page)
            if not match or int(match[1]) not in pages or manifest['files'].get(f'evidence/kerala-1961-pca-{page.replace("_", "-")}.png') != digest:
                raise ValueError('Supplemental rendering not preserved')
        for review_key in ['digit_review_render', 'reading_review_render']:
            if review_key not in evidence:
                continue
            review = evidence[review_key]
            match = re.fullmatch(r'kerala-1961-pca-([0-9]+)-[a-z-]+\.png', review.get('filename', ''))
            if not match or int(match[1]) not in pages or not review.get('sha256') or manifest['files'].get('evidence/' + review['filename']) != review['sha256']:
                raise ValueError('Digit review rendering not preserved')
        for candidate in evidence['rows']:
            identity = f'{serial}:{candidate.get("residence", "")}'
            if candidate.get('original_serial') != serial or candidate.get('original_name') != adapter[1] or candidate.get('parent_original_name') != 'KERALA' or candidate.get('level') != 'DISTRICT' or candidate.get('residence') not in ['Total', 'Rural', 'Urban'] or identity in result or not isinstance(candidate.get('values'), dict) or not isinstance(candidate.get('area_acres_original'), str):
                raise ValueError('District evidence identity differs')
            result[identity] = dict(candidate, source_discrepancy_notes=[note for note in evidence.get('notes', []) if 'Source discrepancy' in note])
    return result


def _validate_reconciliations(rows):
    categories = ['II', 'III', 'IV', 'V', 'VI', 'VII', 'VIII', 'IX']
    groups = {}

    def check(row, field, matches):
        if not matches and not any('Source discrepancy' in flag and field in flag for flag in row['flags']):
            raise ValueError('Unnoted source discrepancy')

    for row in rows:
        values = row['values']
        groups.setdefault((row['level'], row['original_serial']), {})[row['residence']] = row
        for prefix in ['SC', 'ST', 'NON_WORK'] + ['WORK_CATEGORY_' + c for c in categories]:
            if all(prefix + '_' + sex in values for sex in ['P', 'M', 'F']):
                check(row, prefix + '_P', values[prefix + '_P'] == values[prefix + '_M'] + values[prefix + '_F'])
        for sex in ['P', 'M', 'F']:
            if 'NON_WORK_' + sex in values:
                check(row, 'TOT_' + sex, values['TOT_' + sex] == values['TOT_WORK_' + sex] + values['NON_WORK_' + sex])
            fields = ['WORK_CATEGORY_' + c + '_' + sex for c in categories]
            if all(f in values for f in fields):
                check(row, 'TOT_WORK_' + sex, values['TOT_WORK_' + sex] == values['CULTIVATOR_' + sex] + sum(values[f] for f in fields))
    for group in groups.values():
        if not all(residence in group for residence in ['Total', 'Rural', 'Urban']):
            continue
        for field, value in group['Total']['values'].items():
            if type(value) is int and all(field in group[residence]['values'] for residence in ['Rural', 'Urban']):
                check(group['Total'], field, value == group['Rural']['values'][field] + group['Urban']['values'][field])
