"""Prepare a pinned, additive original1961 C-III-A package; no database writes."""
import argparse
import copy
import hashlib
import json
import shutil
import tempfile
import zipfile
from pathlib import Path

import build_national_original_education_package as original
from build_original_education_package import digest, encoded

MAPPING = 'national-state-allages-mapping-v2-20261002T1935.json'
MAPPING_SHA = '634991916e8b3b99f15cf4fb294456c430b3a2c000ad0ec4acd50c9007f09921'
BASE_MANIFEST_SHA = 'b78694d307084e6e4d78076066c25ebe598baa88811e3f3126763f675808dd82'
BASE_PACKAGE_SHA = '9b9416264616f309e37c419d5bf33067cb10e0fb3759889a138c1ad9dd7c344d'
PLACEMENTS = [('32022:C-III-A:119:1', 'ANDHRA PRADESH'),
              ('32022:C-III-A:119:4', 'GUJARAT'),
              ('32022:C-III-A:121:1', 'JAMMU AND KASHMIR'),
              ('32022:C-III-A:121:3', 'MADHYA PRADESH'),
              ('32022:C-III-A:121:4', 'MADRAS')]
NOTES = [
    'Original C-III-A All Areas:12 previously published INDIA* age rows plus5 state All ages rows only; no national geographical or all-topic completeness.',
    'The unchanged INDIA* rows retain their original12-row release notes. Its states-excluded note describes that previous release; the five state additions are explicitly listed in this manifest.',
    'INDIA* alone has the documented NEFA portion exclusion. State rows use their own printed source populations; do not add excluded counts or infer a denominator.',
    'All ages includes0-4. No modern age7+ literacy rates, inferred populations, modern LGD identifiers or name-only geographic joins.',
    'Original dots remain NULL in the eight previously published INDIA* cells. The unresolved Kerala ILLITERATE_F is not publisher missingness; its entire state row is excluded.',
    'Four original INDIA* arithmetic discrepancies and Madras female education sum minus population+50000 remain explicit, with printed values unchanged. No official erratum or publisher error is inferred.',
    'Different source headings are distinct observations; do not sum national and state rows as a single population.',
    'Other ages, residence splits, geographies, informal study zones and topics remain pending. No health or amenity indicator is inferred from educational level.',
]


def map_state_rows(mapping):
    """Validate the original state placements, never accept a review NULL as a dot."""
    records = mapping['mapped_rows']
    if [(r['source_placement_key'], r['original_name']) for r in records] != PLACEMENTS:
        raise ValueError('Reviewed state placement inventory differs')
    rows, checks = [], []
    for candidate in records:
        if (candidate['original_table'] != 'C-III Part A' or candidate['original_age_group'] != 'All ages'
                or candidate['original_residence'] != 'All Areas' or candidate['census_year'] != 1961
                or candidate['publication_year'] != 1964 or candidate['modern_LGD_identifiers'] is not None
                or candidate['official_geography_codes'] is not None
                or list(candidate['values']) != original.FIELDS):
            raise ValueError('Original state scope or identifiers differ')
        left, right = candidate['physical_pages']
        if right != left+1 or candidate['printed_pages'] != [left-7, right-7]:
            raise ValueError('Original paired pages differ')
        block = candidate['original_block_ordinal']
        expected = f"32022:1961:C-III-A:{left}:{block}:{candidate['original_name']}:All Areas:All ages"
        if (candidate['source_record_identity'] != expected
                or candidate['record_key'] != hashlib.sha256(expected.encode()).hexdigest()
                or candidate['source_placement_key'] != f'32022:C-III-A:{left}:{block}'):
            raise ValueError('Original row identity differs')
        for column, field in enumerate(original.FIELDS, 2):
            value = candidate['values'][field]; evidence = candidate['value_evidence'][field]
            page = left if column <= 6 else right
            if (type(value) is not int or value < 0
                    or evidence.get('raw_printed_cell') is None
                    or int(evidence['raw_printed_cell'].replace(',', '')) != value):
                raise ValueError('Unresolved state reading or printed integer differs')
            if (evidence['physical_page'] != page or evidence['printed_page'] != page-7
                    or evidence['source_column'] != column or evidence['source_row_label'] != 'All ages'
                    or evidence['block_ordinal_top_to_bottom'] != block):
                raise ValueError('Original state cell locator differs')
        row = dict(record_key=candidate['record_key'], source_record_identity=expected,
                   original_name=candidate['original_name'], original_level='STATE', parent_original_name=None,
                   original_age_group='All ages', original_row_position_within_geography=1,
                   original_block_ordinal=block, year=1961, table='C-III Part A', residence='All Areas',
                   values=copy.deepcopy(candidate['values']), value_evidence=copy.deepcopy(candidate['value_evidence']),
                   flags=['Original1961 state All ages/All Areas only; other ages and residences pending.',
                          'Source-scoped original heading; not a modern LGD/Census-code mapping or age7+ rate.'],
                   modern_LGD_identifiers=None, boundary_basis=candidate['boundary_basis'],
                   definition_context='Printed population of this source state heading; INDIA* NEFA exclusion is not applied.')
        v = row['values']
        equations = [(['TOT_M', 'TOT_F'], 'TOT_P')]
        equations += [([g+'_'+sex for g in ['ILLITERATE', 'LITERATE_WITHOUT_LEVEL', 'PRIMARY_JUNIOR_BASIC', 'MATRIC_AND_ABOVE']], 'TOT_'+sex) for sex in ['M', 'F']]
        discrepancies = []
        for fields, total in equations:
            difference = sum(v[f] for f in fields)-v[total]
            check = dict(check='Original state components = '+total, original_name=row['original_name'],
                         source_record_identity=expected, source_placement_key=candidate['source_placement_key'],
                         age_group='All ages', reported=v[total], components=[v[f] for f in fields],
                         component_sum=sum(v[f] for f in fields), difference=difference,
                         status='passed' if difference == 0 else 'source_discrepancy')
            checks.append(check)
            if difference:
                discrepancies.append(check)
                row['flags'].append(f"Printed discrepancy: {check['check']}; reported {check['reported']}, components {check['component_sum']}, difference {difference}. Native6000px cells rechecked; original counts retained, no official erratum inferred.")
        flags = candidate['source_discrepancies']
        if candidate['source_placement_key'] == '32022:C-III-A:121:4':
            if (len(discrepancies) != 1 or discrepancies[0]['difference'] != 50000
                    or discrepancies[0]['check'] != 'Original state components = TOT_F'
                    or len(flags) != 1 or not flags[0].get('source_cell_recheck_completed')
                    or flags[0]['difference'] != 50000 or flags[0]['official_erratum_applied']
                    or flags[0]['confirmed_publisher_error']):
                raise ValueError('Reviewed Madras discrepancy inventory differs')
        elif discrepancies or flags:
            raise ValueError('Unexpected state discrepancy')
        rows.append(row)
    return rows, checks


def load_reviewed(root):
    payload, renders = original.load_reviewed(root)
    if digest(root/MAPPING) != MAPPING_SHA:
        raise ValueError('Pinned additive mapping checksum differs')
    mapping = json.loads((root/MAPPING).read_text(encoding='utf-8'))
    if (mapping['original_sha256'] != original.ORIGINAL_SHA or mapping['official_URL'] != original.SOURCE_URL
            or mapping['retrieved_at'] != original.RETRIEVED):
        raise ValueError('Additive source provenance differs')
    members = {**{n:root/n for n in original.PINS}, **renders, MAPPING:root/MAPPING}
    proofs = dict(mapping['witness_hashes']) | dict(mapping['input_hashes'])
    proofs[mapping['geography_map']] = mapping['geography_map_sha256']
    proofs[mapping['previous_mapping']] = mapping['previous_mapping_sha256']
    proofs[mapping['native_cell_recheck_receipt']] = mapping['native_cell_recheck_receipt_sha256']
    for name, expected in proofs.items():
        if Path(name).name != name or digest(root/name) != expected:
            raise ValueError('Additive witness checksum differs: '+name)
        members[name] = root/name
    geography = json.loads((root/mapping['geography_map']).read_text(encoding='utf-8'))
    placements = {p['source_placement_key']:p for p in geography['source_placements']}
    for candidate in mapping['mapped_rows']:
        source = json.loads((root/candidate['candidate_file']).read_text(encoding='utf-8'))
        raw = next(r for r in source['records'] if r['source_placement_key'] == candidate['source_placement_key'])
        placement = placements[candidate['source_placement_key']]
        if (raw['values'] != candidate['values'] or raw['value_evidence'] != candidate['value_evidence']
                or candidate['original_name'] != placement['raw_left_heading']
                or candidate['original_name'] != placement['raw_right_heading']
                or candidate['physical_pages'] != placement['physical_pages']):
            raise ValueError('State mapping differs from pinned original evidence')
    for page in [114,119,120,121,122]:
        render = geography['render_evidence'][str(page)]
        if digest(root/render['filename']) != render['sha256']:
            raise ValueError('Source table heading render differs')
        members[render['filename']] = root/render['filename']
    additions, checks = map_state_rows(mapping)
    prior = copy.deepcopy(payload['rows'])
    payload['rows'] += additions
    if payload['rows'][:12] != prior:
        raise ValueError('Published INDIA* rows changed')
    payload['checks'] += checks
    payload['notes'] = NOTES
    for definition in payload['fields'].values():
        definition['universe'] = 'Printed population of the original source heading and age row. INDIA* alone is subject to its documented NEFA portion exclusion; state rows use their own printed populations.'
    cells = [value for row in payload['rows'] for value in row['values'].values()]
    payload['statistics'] = dict(source_rows=17, retained_INDIA_age_rows=12, added_state_allages_rows=5,
                                 value_cells=len(cells), reported_integer_cells=sum(type(v) is int for v in cells),
                                 original_dot_null_cells=cells.count(None), field_definitions=11,
                                 arithmetic_passes=sum(c['status']=='passed' for c in payload['checks']),
                                 arithmetic_discrepancies=sum(c['status']=='source_discrepancy' for c in payload['checks']),
                                 arithmetic_skips=sum(c['status']=='skipped_original_missing_cells' for c in payload['checks']),
                                 excluded_unresolved_state_rows=1)
    expected = dict(source_rows=17, retained_INDIA_age_rows=12, added_state_allages_rows=5,
                    value_cells=187, reported_integer_cells=179, original_dot_null_cells=8, field_definitions=11,
                    arithmetic_passes=49, arithmetic_discrepancies=5, arithmetic_skips=10,
                    excluded_unresolved_state_rows=1)
    if payload['statistics'] != expected or len({r['record_key'] for r in payload['rows']}) != 17:
        raise ValueError('Additive coverage or statistics differ')
    return payload, members


def manifest(files, statistics):
    return dict(family='historical-census-original-national-education', version=2, catalogue='32022', year=1961,
                publication_year=1964, source_key='census-original-education-32022-1961-c3a',
                original='original/'+original.ORIGINAL, original_sha256=original.ORIGINAL_SHA,
                source_url=original.SOURCE_URL, retrieved_at=original.RETRIEVED,
                boundary_basis='Original source-era headings; INDIA* partial NEFA exclusion applies only to INDIA*',
                scope='C-III Part A, All Areas:12 unchanged INDIA* age rows plus5 reviewed original state All ages rows; person-count columns2-12',
                files=files, statistics=statistics, notes=NOTES, original_age_groups=original.AGES,
                additional_state_placements=PLACEMENTS, prior_manifest_sha256=BASE_MANIFEST_SHA,
                prior_package_sha256=BASE_PACKAGE_SHA,
                database_status='Prepared data only; expanded tested adapter and transactional additive import required')


def build(root, output):
    if output.exists() or not output.parent.is_dir():
        raise ValueError('Output exists or destination absent')
    if min(shutil.disk_usage(root).free,shutil.disk_usage(output.parent).free) < 10*1024**3:
        raise ValueError('Disk reserve below10GiB')
    payload, proofs = load_reviewed(root)
    members = {'original/'+original.ORIGINAL:root/original.ORIGINAL,
               **{'evidence/'+name:path for name,path in proofs.items()}}
    files = {name:digest(path) for name,path in members.items()}
    raw = encoded(payload);files['education-population.json']=hashlib.sha256(raw).hexdigest()
    with tempfile.NamedTemporaryFile(dir=output.parent,suffix='.zip',delete=False) as stream:
        temporary=Path(stream.name)
    try:
        with zipfile.ZipFile(temporary,'w',zipfile.ZIP_STORED) as archive:
            for name,path in members.items():
                with archive.open(zipfile.ZipInfo(name,(1980,1,1,0,0,0)),'w') as target,path.open('rb') as source:
                    shutil.copyfileobj(source,target,262144)
            archive.writestr(zipfile.ZipInfo('education-population.json',(1980,1,1,0,0,0)),raw)
            archive.writestr(zipfile.ZipInfo('manifest.json',(1980,1,1,0,0,0)),encoded(manifest(files,payload['statistics'])))
        verify(temporary,digest(temporary))
        with output.open('xb') as target,temporary.open('rb') as source:
            shutil.copyfileobj(source,target,262144)
    finally:
        temporary.unlink(missing_ok=True)
    with zipfile.ZipFile(output) as archive:
        manifest_sha=hashlib.sha256(archive.read('manifest.json')).hexdigest()
    return dict(package=output.name,sha256=digest(output),manifest_sha256=manifest_sha,
                bytes=output.stat().st_size,statistics=payload['statistics'],database_writes=0)


def verify(path, expected_sha):
    if digest(path) != expected_sha:
        raise ValueError('Package checksum differs')
    with zipfile.ZipFile(path) as archive,tempfile.TemporaryDirectory() as directory:
        names=archive.namelist();claimed=json.loads(archive.read('manifest.json'))
        if (len(names)!=len(set(names)) or set(names)!=set(claimed['files'])|{'manifest.json'}
                or any(name.startswith('/') or '..' in Path(name).parts for name in names)
                or len({Path(name).name for name in names})!=len(names)):
            raise ValueError('Duplicate, unsafe or unexpected archive inventory')
        root=Path(directory)
        for name,expected in claimed['files'].items():
            if archive.getinfo(name).file_size>50_000_000:
                raise ValueError('Oversized member')
            with archive.open(name) as source,(root/Path(name).name).open('xb') as target:
                shutil.copyfileobj(source,target,262144)
            if digest(root/Path(name).name)!=expected:
                raise ValueError('Member checksum differs')
        payload,proofs=load_reviewed(root)
        exact={'original/'+original.ORIGINAL} | {'evidence/'+name for name in proofs} | {'education-population.json','manifest.json'}
        if set(names)!=exact:
            raise ValueError('Unexpected archive inventory')
        if json.loads((root/'education-population.json').read_text(encoding='utf-8'))!=payload:
            raise ValueError('Mapped payload differs from pinned evidence')
        if claimed!=json.loads(encoded(manifest(claimed['files'],payload['statistics']))):
            raise ValueError('Manifest source, scope or statistics differ')
        return payload


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('root',type=Path);parser.add_argument('output',type=Path)
    args=parser.parse_args();print(json.dumps(build(args.root,args.output),indent=2))
