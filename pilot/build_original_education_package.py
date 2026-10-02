"""Package pinned, visually reviewed Tripura 1961 education population evidence.

This prepares data only. It does not use the PCA importer or write a database.
"""
import argparse
import hashlib
import json
import shutil
import tempfile
import zipfile
from pathlib import Path, PurePosixPath

SOURCE_URL = 'https://censusindia.gov.in/nada/index.php/catalog/30470/download/33651/24040_1961_GPET.pdf'
ORIGINAL = '24040_1961_GPET.pdf'
ORIGINAL_SHA = '0996de40077ca9b2d6f861584a174c36e8b826fc55bec7710f965c3e75d007c9'
RETRIEVED = '2026-10-01T15:46:41.827938+00:00'
PINS = {
    'tripura-urban-education-verified-candidates-20261002T0704.json': '119ea4868d09f4777f025aa5ea1444f90f3e577dc7ce75d0d75d14a6fa8f1972',
    'tripura-urban-subdivision-education-candidates-20261002T0809.json': 'ff4f0c019349cc0e31cb7eb8e8562aa3ac1f9582cae27c5250d71b5578015bb2',
    'tripura-rural-education-verified-candidates-v2-20261002T0754.json': 'e5cd483a160cbd5a88353c16d9184bc1c5147f8b95debe3bcc9f66546e622d70',
}
URBAN = [
    ('Total', 'TOT'), ('Illiterate', 'ED_A_ILLITERATE'),
    ('Literate (without educational level)', 'ED_A_LITERATE_NO_LEVEL'),
    ('Primary or Junior Basic', 'ED_A_PRIMARY_JUNIOR_BASIC'),
    ('Matriculation or Higher Secondary', 'ED_A_MATRIC_HIGHER_SECONDARY'),
    ('Technical diploma not equal to degree', 'ED_A_TECH_DIPLOMA_NOT_DEGREE'),
    ('Non-technical diploma not equal to degree', 'ED_A_NONTECH_DIPLOMA_NOT_DEGREE'),
    ('University degree or post graduate degree other than technical degree', 'ED_A_UNIVERSITY_NONTECH'),
    ('Technical degree or diploma equal to degree or post-graduate degree', 'ED_A_TECH_DEGREE'),
    ('Engineering', 'ED_A_ENGINEERING'), ('Medicine', 'ED_A_MEDICINE'),
    ('Agriculture', 'ED_A_AGRICULTURE'), ('Veterinary and dairying', 'ED_A_VETERINARY_DAIRYING'),
    ('Technology', 'ED_A_TECHNOLOGY'), ('Teaching', 'ED_A_TEACHING'), ('Others', 'ED_A_OTHERS'),
]
RURAL = [
    ('Total', 'TOT'), ('Illiterate', 'ED_B_ILLITERATE'),
    ('Literate (without educational levels)', 'ED_B_LITERATE_NO_LEVEL'),
    ('Primary or Junior Basic', 'ED_B_PRIMARY_JUNIOR_BASIC'),
    ('Matriculation and above', 'ED_B_MATRIC_AND_ABOVE'),
]
URBAN_PAGES = {'TRIPURA': 168, 'SADAR SUB-DIVISION': 168, 'KHOWAI SUB-DIVISION': 170,
               'KAILASAHAR SUB-DIVISION': 170, 'DHARMANAGAR SUB-DIVISION': 172,
               'UDAIPUR SUB-DIVISION': 172, 'BELONIA SUB-DIVISION': 174}
RURAL_PAGES = {name: 178 for name in ['TRIPURA', 'SADAR SUB-DIVISION', 'KHOWAI SUB-DIVISION',
               'KAMALPUR SUB-DIVISION', 'KAILASAHAR SUB-DIVISION', 'DHARMANAGAR SUB-DIVISION']}
RURAL_PAGES.update({name: 180 for name in ['SONAMURA SUB-DIVISION', 'UDAIPUR SUB-DIVISION',
                   'AMARPUR SUB-DIVISION', 'BELONIA SUB-DIVISION', 'SABROOM SUB-DIVISION']})
NOTES = [
    '1961 source geography retained; source heading identities are scoped to catalogue 30470 and table, with no modern LGD or name-only joins.',
    'Population of workers and non-workers by educational level; no age-seven-plus literacy rate, enrolment, occupation count or facility availability inferred.',
    'Urban B-III Part A and rural B-III Part B have different classification schemes; no crosswalk or combined Total residence inferred.',
    'Urban technical branches overlap their technical-degree parent; never add child branches again to the eight top-level groups.',
    'Original ellipses are null, never zero-imputed. Arithmetic checks requiring a missing cell are skipped.',
    'Worker cross-tabulations remain incomplete and are excluded. Rural Other Services prints XI whereas urban prints IX; that header discrepancy does not affect packaged population columns 2-4.',
    'Coverage is Tripura territory and six urban / ten rural original subdivision headings, not national education completeness.',
]


def digest(path):
    h = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024 * 1024), b''):
            h.update(block)
    return h.hexdigest()


def encoded(value):
    return (json.dumps(value, ensure_ascii=False, indent=2) + '\n').encode('utf-8')


def map_group(cells, residence, geography, page):
    """Map a complete reviewed table panel without inferring missing cells."""
    scheme = URBAN if residence == 'Urban' else RURAL if residence == 'Rural' else None
    if scheme is None or len(cells) != len(scheme):
        raise ValueError('Incomplete education panel or unsupported residence')
    table = 'B-III Part A' if residence == 'Urban' else 'B-III Part B'
    identity = f'30470:1961:{table}:{geography}:{residence}'
    row = dict(record_key=hashlib.sha256(identity.encode()).hexdigest(), source_record_identity=identity,
               original_name=geography, original_level='TERRITORY' if geography == 'TRIPURA' else 'SUB-DIVISION',
               parent_original_name=None if geography == 'TRIPURA' else 'TRIPURA', year=1961,
               table=table, residence=residence, values={}, value_evidence={}, flags=list(NOTES))
    checks = []

    def check(name, expected, parts):
        if expected is None or any(v is None for v in parts):
            status = 'skipped_missing_source_cell'
        else:
            status = 'passed' if expected == sum(parts) else 'source_discrepancy'
        checks.append(dict(identity=identity, check=name, reported=expected, components=parts, status=status))
        if status == 'source_discrepancy':
            row['flags'].append(f'Source discrepancy: {name}; reported {expected}, components {sum(parts)}. Original retained.')

    for index, (cell, (label, prefix)) in enumerate(zip(cells, scheme)):
        actual_label = cell.get('original_educational_level', cell.get('educational_level_original'))
        ordinal = cell.get('source_row_sequence', cell.get('original_row_ordinal', -1) + 1)
        if (actual_label != label or ordinal != index + 1 or cell.get('original_geography') != geography
                or cell.get('residence') != residence or cell.get('physical_page') != page
                or cell.get('printed_page') != page - 4 or cell.get('source_columns') != dict(P=2, M=3, F=4)
                or cell.get('table', table) != table):
            raise ValueError('Source label, order, identity or locator differs')
        if geography != 'TRIPURA' and (cell.get('level') != 'SUB-DIVISION' or cell.get('parent_original_geography') != 'TRIPURA'):
            raise ValueError('Original parent differs')
        parent = URBAN[8][0] if residence == 'Urban' and index >= 9 else None
        if cell.get('parent_educational_level') != parent:
            raise ValueError('Technical subgroup hierarchy differs')
        values = cell.get('values', {})
        if set(values) != {'P', 'M', 'F'}:
            raise ValueError('Missing sex columns')
        for sex, value in values.items():
            notation = cell.get('missing_cell_notation', {}).get(sex)
            if value is None:
                if residence != 'Urban' or not isinstance(notation, str) or not ('...' in notation or 'ellipsis' in notation.lower()):
                    raise ValueError('Null lacks original ellipsis evidence')
            elif type(value) is not int or value < 0 or notation:
                raise ValueError('Invalid reported count or missing-cell notation')
            field = f'{prefix}_{sex}'
            row['values'][field] = value
            row['value_evidence'][field] = dict(original_label=label, parent_original_label=parent,
                source_row_sequence=index + 1, physical_page=page, printed_page=page - 4,
                source_column=cell['source_columns'][sex], missing_cell_notation=notation,
                original_table=table)
        check(prefix + '_P=M+F', values['P'], [values['M'], values['F']])
    top_indices = range(1, 9) if residence == 'Urban' else range(1, 5)
    for sex in ['P', 'M', 'F']:
        check(f'TOT_{sex}=top_level_groups', cells[0]['values'][sex], [cells[i]['values'][sex] for i in top_indices])
        if residence == 'Urban':
            check(f'ED_A_TECH_DEGREE_{sex}=branches', cells[8]['values'][sex], [cells[i]['values'][sex] for i in range(9, 16)])
    return row, checks


def load_reviewed(root):
    docs = []
    for name, sha in PINS.items():
        if digest(root / name) != sha:
            raise ValueError('Pinned reviewed candidate checksum differs: ' + name)
        doc = json.loads((root / name).read_text(encoding='utf-8'))
        if (doc.get('source_catalogue') != 30470 or doc.get('year', doc.get('actual_census_year')) != 1961
                or doc.get('original_sha256') != ORIGINAL_SHA or doc.get('source_url') != SOURCE_URL
                or doc.get('original_retrieved_at', doc.get('retrieved_at')) != RETRIEVED):
            raise ValueError('Original-source provenance differs')
        docs.append(doc)
    with (root / ORIGINAL).open('rb') as original:
        signature = original.read(5)
    if digest(root / ORIGINAL) != ORIGINAL_SHA or signature != b'%PDF-':
        raise ValueError('Original PDF checksum differs')
    renders = {}
    for doc in docs:
        for key in ['render_hashes', 'definition_render_hashes']:
            for page, sha in doc.get(key, {}).items():
                if str(page) in renders and renders[str(page)] != sha:
                    raise ValueError('Conflicting render checksum')
                renders[str(page)] = sha
        for cell in doc.get('records', []):
            if 'render_sha256' in cell:
                page = str(cell['physical_page'])
                if page in renders and renders[page] != cell['render_sha256']:
                    raise ValueError('Conflicting render checksum')
                renders[page] = cell['render_sha256']
    rows, checks = [], []
    panels = [('Urban', docs[0]['rows'] + docs[1]['records'], URBAN_PAGES), ('Rural', docs[2]['records'], RURAL_PAGES)]
    for residence, cells, coverage in panels:
        if set(c['original_geography'] for c in cells) != set(coverage):
            raise ValueError('Original geography coverage differs')
        for geography, page in coverage.items():
            row, group_checks = map_group([c for c in cells if c['original_geography'] == geography], residence, geography, page)
            for cell in row['value_evidence'].values():
                cell['render_sha256'] = renders[str(page)]
            rows.append(row)
            checks.extend(group_checks)
    for page, sha in renders.items():
        if digest(root / f'tripura-1961-education-{page}.png') != sha:
            raise ValueError('Page evidence checksum differs')
    checks.extend(reconcile_geography(rows))
    return rows, checks, renders


def reconcile_geography(rows):
    checks = []
    for residence in ['Urban', 'Rural']:
        group = [r for r in rows if r['residence'] == residence]
        territory = next(r for r in group if r['original_name'] == 'TRIPURA')
        children = [r for r in group if r['original_name'] != 'TRIPURA']
        for field, value in territory['values'].items():
            parts = [r['values'][field] for r in children]
            if value is None or any(v is None for v in parts):
                status = 'skipped_missing_source_cell'
            else:
                status = 'passed' if value == sum(parts) else 'source_discrepancy'
            name = f'{field}=reported_{residence.lower()}_subdivision_headings'
            checks.append(dict(identity=territory['source_record_identity'], check=name,
                               reported=value, components=parts, status=status))
            if status == 'source_discrepancy':
                territory['flags'].append(f'Source discrepancy: {name}; reported {value}, components {sum(parts)}. Original retained.')
    return checks


def definitions():
    fields = {}
    for residence, scheme in [('Urban', URBAN), ('Rural', RURAL)]:
        for index, (label, prefix) in enumerate(scheme):
            for sex in ['P', 'M', 'F']:
                fields[f'{prefix}_{sex}'] = dict(original_label=label, sex=sex, unit='persons',
                    residence_scheme='Both source population totals' if prefix == 'TOT' else residence,
                    parent_field=f'ED_A_TECH_DEGREE_{sex}' if residence == 'Urban' and index >= 9 else None,
                    additive_to_total=index in (range(1, 9) if residence == 'Urban' else range(1, 5)),
                    universe='Whole reported population of workers and non-workers; no age-seven-plus restriction inferred')
    return fields


def build(root, output):
    if output.exists():
        raise ValueError('Output already exists; preserve prior package')
    if shutil.disk_usage(root).free < 10 * 1024 ** 3:
        raise ValueError('Disk reserve below 10 GiB')
    rows, checks, renders = load_reviewed(root)
    stats = statistics(rows, checks)
    if {k: stats[k] for k in ['geographical_records', 'value_cells', 'reported_integer_cells', 'original_ellipsis_null_cells', 'field_definitions']} != dict(geographical_records=18, value_cells=501, reported_integer_cells=425, original_ellipsis_null_cells=76, field_definitions=60):
        raise ValueError('Reviewed population scope differs')
    payload = encoded(dict(rows=rows, checks=checks, statistics=stats, fields=definitions(), notes=NOTES))
    members = {'original/' + ORIGINAL: root / ORIGINAL}
    members.update({'evidence/' + name: root / name for name in PINS})
    members.update({f'evidence/tripura-1961-education-{page}.png': root / f'tripura-1961-education-{page}.png' for page in renders})
    files = {name: digest(path) for name, path in members.items()}
    files['education-population.json'] = hashlib.sha256(payload).hexdigest()
    manifest = dict(family='historical-census-original-education', version=1, catalogue='30470', year=1961,
        publication_year=1964, source_key='census-original-education-30470-1961', original='original/' + ORIGINAL,
        original_sha256=ORIGINAL_SHA, source_url=SOURCE_URL, retrieved_at=RETRIEVED,
        boundary_basis='Original 1961 Tripura territory and source subdivision headings; no modern crosswalk',
        scope='B-III A urban / B-III B rural population columns 2-4 only', files=files, statistics=stats,
        original_geography_coverage=dict(Urban=list(URBAN_PAGES), Rural=list(RURAL_PAGES)),
        definition_pages=dict(Urban=[166, 167], Rural=[177]), notes=NOTES,
        database_status='Prepared only; requires a compatible tested education importer, backup and transactional publication')
    with tempfile.NamedTemporaryFile(dir=output.parent, suffix='.zip', delete=False) as temp:
        temporary = Path(temp.name)
    try:
        with zipfile.ZipFile(temporary, 'w', zipfile.ZIP_DEFLATED, compresslevel=1) as archive:
            for name, path in members.items():
                archive.write(path, name)
            archive.writestr('education-population.json', payload)
            archive.writestr('manifest.json', encoded(manifest))
        verify(temporary, digest(temporary))
        with output.open('xb') as target, temporary.open('rb') as source:
            shutil.copyfileobj(source, target, 1024 * 1024)
    finally:
        temporary.unlink(missing_ok=True)
    return dict(package=output.name, sha256=digest(output), bytes=output.stat().st_size, **stats)


def verify(path, expected_sha):
    if digest(path) != expected_sha:
        raise ValueError('Package checksum differs')
    with zipfile.ZipFile(path) as archive, tempfile.TemporaryDirectory() as directory:
        names = archive.namelist()
        if len(names) != len(set(names)):
            raise ValueError('Duplicate archive member')
        manifest = json.loads(archive.read('manifest.json'))
        if manifest.get('family') != 'historical-census-original-education' or manifest.get('version') != 1:
            raise ValueError('Unsupported education package')
        if set(names) != set(manifest['files']) | {'manifest.json'}:
            raise ValueError('Unregistered archive member')
        root = Path(directory)
        for name, sha in manifest['files'].items():
            member = PurePosixPath(name)
            if member.is_absolute() or '..' in member.parts or '\\' in name or archive.getinfo(name).file_size > 50_000_000:
                raise ValueError('Unsafe or oversized archive member')
            # Flatten only known evidence; no archive path extraction.
            if name == 'education-population.json':
                if hashlib.sha256(archive.read(name)).hexdigest() != sha:
                    raise ValueError('Member checksum differs')
                continue
            with archive.open(name) as source, (root / member.name).open('xb') as target:
                shutil.copyfileobj(source, target, 1024 * 1024)
            if digest(root / member.name) != sha:
                raise ValueError('Member checksum differs')
        rows, checks, renders = load_reviewed(root)
        payload = json.loads(archive.read('education-population.json'))
        if payload.get('rows') != rows or payload.get('checks') != checks or payload.get('fields') != definitions() or payload.get('notes') != NOTES:
            raise ValueError('Mapped payload differs from pinned reviewed evidence')
        if (manifest.get('original_sha256') != ORIGINAL_SHA or manifest.get('source_url') != SOURCE_URL
                or manifest.get('year') != 1961 or manifest.get('catalogue') != '30470'
                or manifest.get('retrieved_at') != RETRIEVED or manifest.get('source_key') != 'census-original-education-30470-1961'
                or manifest.get('notes') != NOTES or manifest.get('publication_year') != 1964
                or manifest.get('original') != 'original/' + ORIGINAL
                or manifest.get('original_geography_coverage') != dict(Urban=list(URBAN_PAGES), Rural=list(RURAL_PAGES))
                or manifest.get('definition_pages') != dict(Urban=[166, 167], Rural=[177])):
            raise ValueError('Package provenance differs')
        expected_names = {'original/' + ORIGINAL, 'education-population.json'} | {'evidence/' + name for name in PINS} | {f'evidence/tripura-1961-education-{page}.png' for page in renders}
        if set(manifest['files']) != expected_names or manifest.get('statistics') != statistics(rows, checks) or payload.get('statistics') != statistics(rows, checks):
            raise ValueError('Package inventory or counts differ')
        return payload


def statistics(rows, checks):
    cells = [v for row in rows for v in row['values'].values()]
    return dict(geographical_records=len(rows), value_cells=len(cells), reported_integer_cells=sum(type(v) is int for v in cells),
                original_ellipsis_null_cells=cells.count(None), field_definitions=len(definitions()),
                arithmetic_passes=sum(c['status'] == 'passed' for c in checks),
                arithmetic_discrepancies=sum(c['status'] == 'source_discrepancy' for c in checks),
                arithmetic_skips=sum(c['status'] == 'skipped_missing_source_cell' for c in checks))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('root', type=Path)
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    print(json.dumps(build(args.root, args.output), indent=2))
