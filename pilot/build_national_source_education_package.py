"""Package reviewed original1961 source areas and informal study zones, data only."""
import argparse
import copy
import hashlib
import json
import shutil
import tempfile
import zipfile
from pathlib import Path

BASE_PACKAGE = 'national-original-education-expansion-1961-v2.zip'
BASE_PACKAGE_SHA = '767226ed5585e6ca6d3f0579cdf7f4e2c8ef54e4ac7706969d66bd9e4026de19'
BASE_PAYLOAD = 'national-prior-education-population-v2.json'
BASE_PAYLOAD_SHA = '987e90197d1142e18f90d7ca20d96540a482e9e07f1dcbf6cc28945934afd1ad'
BASE_MANIFEST = 'national-prior-release-manifest-v2.json'
BASE_MANIFEST_SHA = 'bdf136df774f131d053549863adc06d94d051882687d8d2049137d89f4470179'
MAPPING = 'national-complete-source-placement-mapping-20261003T0109.json'
MAPPING_SHA = '8082a2e3509338af9fe586d16dde5d234981a46ee5b3f9947f3ed508692a2302'
RECEIPT = 'national-complete-source-mapping-verification-20261003T0109.json'
RECEIPT_SHA = '5265e4d426078d18fb99df246963bab9a15ba3dd2bcc21e162c5e298199cb507'
ORIGINAL = '22949_1961_SCT.pdf'
ORIGINAL_SHA = '30179b02a44bdd62baeebb1bb49b2676184f848110e8e140c153f3a2925009b8'
FIELDS = ['TOT_P', 'TOT_M', 'TOT_F', 'ILLITERATE_M', 'ILLITERATE_F',
          'LITERATE_WITHOUT_LEVEL_M', 'LITERATE_WITHOUT_LEVEL_F',
          'PRIMARY_JUNIOR_BASIC_M', 'PRIMARY_JUNIOR_BASIC_F',
          'MATRIC_AND_ABOVE_M', 'MATRIC_AND_ABOVE_F']
NOTES = [
    'Original C-III-A All Areas only:12 retained INDIA* age rows, five retained state All ages rows and25 additional complete source placements.',
    'The25 additions comprise22 source-area placements and three informal study zones; their administrative levels and parents remain unassigned.',
    'Thirty All ages non-national placements represent25 distinct complete original areas and three study zones. Both Andaman and Goa pairs remain separate.',
    'Four held Central/Eastern/Assam/Kerala rows remain excluded; reading holds are not original missing cells.',
    'Eight original dot NULLs in prior INDIA* rows remain unchanged; new rows contain275 printed integers and no NULLs.',
    'Prior17 row objects and their flags remain byte-equivalent JSON objects. Prior release scope notes describe that release, not the expanded manifest scope.',
    'Source page/block/heading/age/residence identities remain distinct; existing Bihar identity notation is preserved.',
    'Printed Madras50000, Tripura200, Dadra1 and four prior INDIA* discrepancies remain unchanged. No official erratum or inferred numeric repair.',
    'Raw INDIA*/NEFA markers, covered-portion exclusion and constituent enumeration dates remain source-specific; no inferred full NEFA total.',
    'No modern LGD/name-only geographic join, inferred age7+ rate, health or amenity indicator. Other original ages/residences/geographies/topics remain pending.',
    'This data package requires a separately tested compatible importer; no database write or national completeness is established.'
]
EXPECTED = dict(source_rows=42, retained_prior_rows=17, added_source_placements=25,
                additional_source_area_placements=22, additional_informal_study_zones=3,
                value_cells=462, reported_integer_cells=454, original_dot_null_cells=8,
                field_definitions=11, arithmetic_passes=122, arithmetic_discrepancies=7,
                arithmetic_skips=10, excluded_reading_hold_placements=4)


def digest(path):
    checksum = hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda: stream.read(1024*1024), b''):
            checksum.update(block)
    return checksum.hexdigest()


def encoded(value):
    return (json.dumps(value, ensure_ascii=False, indent=2)+'\n').encode('utf-8')


def object_sha(value):
    return hashlib.sha256(json.dumps(value, ensure_ascii=True, sort_keys=True,
                                     separators=(',', ':')).encode()).hexdigest()


def safe_name(name):
    if not isinstance(name, str) or Path(name).name != name or '/' in name or '\\' in name:
        raise ValueError('Unsafe evidence filename')
    return name


def prepare_prior(root):
    """Preserve two exact prior archive members; skip existing equal files."""
    if digest(root/BASE_PACKAGE) != BASE_PACKAGE_SHA:
        raise ValueError('Prior package checksum differs')
    with zipfile.ZipFile(root/BASE_PACKAGE) as archive:
        for source, target, expected in [('education-population.json', BASE_PAYLOAD, BASE_PAYLOAD_SHA),
                                         ('manifest.json', BASE_MANIFEST, BASE_MANIFEST_SHA)]:
            raw = archive.read(source)
            if hashlib.sha256(raw).hexdigest() != expected:
                raise ValueError('Prior archive member checksum differs')
            path = root/target
            if path.exists():
                if digest(path) != expected:
                    raise ValueError('Existing prior evidence differs')
            else:
                with path.open('xb') as stream:
                    stream.write(raw)


def read_pinned(root, name, expected):
    safe_name(name)
    if digest(root/name) != expected:
        raise ValueError('Pinned evidence checksum differs: '+name)
    return json.loads((root/name).read_bytes())


def witness_pins(value):
    """Collect explicit file/hash pairs; never discover witnesses by names alone."""
    found = {}
    pairs = [('inspection_crop', 'inspection_crop_sha256'), ('source_render', 'render_sha256'),
             ('source_render', 'source_render_sha256'), ('detail_render', 'detail_render_sha256'),
             ('inspection_row_render', 'inspection_row_render_sha256'),
             ('parent_row_crop', 'parent_row_crop_sha256'),
             ('native_cell_detail', 'native_cell_detail_sha256'), ('native_row_image', 'native_row_image_sha256')]
    if isinstance(value, dict):
        for filename, checksum in pairs:
            if isinstance(value.get(filename), str) and isinstance(value.get(checksum), str):
                name = safe_name(value[filename]); sha = value[checksum]
                if name in found and found[name] != sha:
                    raise ValueError('Conflicting witness hashes')
                found[name] = sha
        for child in value.values():
            for name, sha in witness_pins(child).items():
                if name in found and found[name] != sha:
                    raise ValueError('Conflicting witness hashes')
                found[name] = sha
    elif isinstance(value, list):
        for child in value:
            for name, sha in witness_pins(child).items():
                if name in found and found[name] != sha:
                    raise ValueError('Conflicting witness hashes')
                found[name] = sha
    return found


def map_additions(mapping, prior, docs):
    if mapping['statistics']['mapped_placements'] != 30 or len(mapping['mapped_rows']) != 30:
        raise ValueError('Complete source mapping inventory differs')
    old = {r['record_key']: r for r in prior['rows']}
    if len(old) != 17:
        raise ValueError('Prior record keys differ')
    rows, checks, retained = [], [], []
    for candidate in mapping['mapped_rows']:
        ref = candidate['source_evidence_reference']
        parent = docs[ref['filename']][ref['container']][ref['index']]
        if (object_sha(parent) != ref['row_sha256'] or candidate['values'] != parent['values']
                or candidate['value_evidence'] != parent['value_evidence']
                or candidate['original_level'] is not None or candidate['parent_original_name'] is not None
                or candidate['modern_LGD_identifiers'] is not None or candidate['official_geography_codes'] is not None
                or candidate['administrative_classification_newly_asserted'] is not False
                or list(candidate['values']) != FIELDS
                or any(type(v) is not int or v < 0 for v in candidate['values'].values())):
            raise ValueError('Unresolved or altered source mapping')
        identity = candidate['source_record_identity']
        left = candidate['physical_pages'][0]; block = candidate['original_block_ordinal']
        allowed = [f"32022:1961:C-III-A:{left}:{block}:{candidate['raw_left_heading']}:All Areas:All ages",
                   f"32022|1961|C-III Part A|{candidate['source_placement_key']}|All Areas|All ages"]
        if (identity not in allowed or candidate['record_key'] != hashlib.sha256(identity.encode()).hexdigest()
                or candidate['source_placement_key'] != f'32022:C-III-A:{left}:{block}'):
            raise ValueError('Source identity differs')
        if candidate['record_key'] in old:
            previous = old[candidate['record_key']]
            if (previous['source_record_identity'] != identity or previous['values'] != candidate['values']
                    or previous['value_evidence'] != candidate['value_evidence']):
                raise ValueError('Previously packaged state row differs')
            retained.append(candidate['record_key'])
            continue
        row = copy.deepcopy(candidate)
        row['year'] = row['census_year']
        row['flags'] += copy.deepcopy(parent.get('flags', []))
        row['flags'].append('Source-specific parent evidence remains pinned; prior review notes are not a modern crosswalk.')
        for diagnostic in candidate['diagnostics']:
            if diagnostic['status'] not in ('pass', 'retained_printed_discrepancy'):
                raise ValueError('Held diagnostic in complete package')
            check = dict(diagnostic, source_record_identity=identity, age_group='All ages',
                         check='Printed source components = '+diagnostic['total_field'],
                         status='passed' if diagnostic['status'] == 'pass' else 'source_discrepancy')
            checks.append(check)
            if check['difference']:
                row['flags'].append(f"Printed discrepancy for {check['total_field']}: difference {check['difference']}; counts unchanged, no official erratum inferred.")
        rows.append(row)
    if len(retained) != 5 or len(rows) != 25 or len({r['record_key'] for r in rows}) != 25:
        raise ValueError('Additive record inventory differs')
    return rows, checks


def load_reviewed(root):
    prior = read_pinned(root, BASE_PAYLOAD, BASE_PAYLOAD_SHA)
    base_manifest = read_pinned(root, BASE_MANIFEST, BASE_MANIFEST_SHA)
    mapping = read_pinned(root, MAPPING, MAPPING_SHA)
    receipt = read_pinned(root, RECEIPT, RECEIPT_SHA)
    pins = {BASE_PAYLOAD: BASE_PAYLOAD_SHA, BASE_MANIFEST: BASE_MANIFEST_SHA,
            MAPPING: MAPPING_SHA, RECEIPT: RECEIPT_SHA}
    if (not receipt['hashes_match'] or receipt['mapping_statistics'] != mapping['statistics']
            or receipt['tests_passed'] != 13 or receipt['database_writes'] != 0):
        raise ValueError('Completed source mapping receipt differs')
    pins.update(receipt['input_hashes'])
    # Reuse the exact old release objects and all its witnesses, without rerunning
    # the completed numeric transcription or original validators.
    for path, sha in base_manifest['files'].items():
        if path == 'education-population.json':
            if sha != BASE_PAYLOAD_SHA:
                raise ValueError('Prior payload hash differs')
            continue
        name = safe_name(Path(path).name)
        if name in pins and pins[name] != sha:
            raise ValueError('Conflicting prior evidence hash')
        pins[name] = sha
    docs = {}
    for name, sha in list(pins.items()):
        safe_name(name)
        if digest(root/name) != sha:
            raise ValueError('Input evidence checksum differs: '+name)
        if name.endswith('.json'):
            docs[name] = json.loads((root/name).read_bytes())
    if digest(root/ORIGINAL) != ORIGINAL_SHA:
        raise ValueError('Original PDF checksum differs')
    coverage = docs[mapping['coverage_reference']['filename']]
    if (coverage['original_sha256'] != ORIGINAL_SHA or mapping['original_sha256'] != ORIGINAL_SHA
            or mapping['official_url'] != coverage['official_url'] or mapping['retrieved_at'] != coverage['retrieved_at']):
        raise ValueError('Source provenance differs')
    expected_excluded = [e for e in coverage['entries'] if not e['numerically_complete']]
    if mapping['excluded_reading_hold_placements'] != expected_excluded or len(expected_excluded) != 4:
        raise ValueError('Held rows or reading notes differ')
    if mapping['repeated_placement_checks'] != coverage['repeated_placement_checks']:
        raise ValueError('Repeated source placements differ')
    for row in mapping['mapped_rows']:
        entry = next(e for e in coverage['entries'] if e['source_placement_key'] == row['source_placement_key'])
        pairs = [('raw_left_heading', 'raw_left_heading'), ('raw_right_heading', 'raw_right_heading'),
                 ('physical_pages', 'physical_pages'), ('printed_pages', 'printed_pages'),
                 ('enumeration_date', 'enumeration_date'), ('warning_ids', 'warning_ids'),
                 ('source_evidence_reference', 'selected_evidence')]
        if not entry['numerically_complete'] or any(row[a] != entry[b] for a,b in pairs):
            raise ValueError('Source placement metadata differs')
        for name, sha in witness_pins(row['value_evidence']).items():
            if name in pins and pins[name] != sha:
                raise ValueError('Conflicting native witness hash')
            pins[name] = sha
    for name, sha in pins.items():
        if digest(root/name) != sha:
            raise ValueError('Native witness checksum differs: '+name)
    additions, checks = map_additions(mapping, prior, docs)
    payload = copy.deepcopy(prior)
    payload['rows'] += additions
    payload['checks'] += checks
    payload['notes'] = copy.deepcopy(NOTES)
    payload['source_placement_context'] = [dict(source_placement_key=r['source_placement_key'],
        record_key=r['record_key'], raw_left_heading=r['raw_left_heading'], raw_right_heading=r['raw_right_heading'],
        source_scope_kind=r['source_scope_kind'], enumeration_date=r['enumeration_date'],
        warning_ids=copy.deepcopy(r['warning_ids']), source_evidence_reference=copy.deepcopy(r['source_evidence_reference']))
        for r in mapping['mapped_rows']]
    for definition in payload['fields'].values():
        definition['universe'] = 'Printed population of each original source heading and age row; INDIA* and NEFA retain only their own documented covered-portion exclusions.'
    cells = [v for r in payload['rows'] for v in r['values'].values()]
    statistics = dict(source_rows=len(payload['rows']), retained_prior_rows=len(prior['rows']),
        added_source_placements=len(additions),
        additional_source_area_placements=sum(r['source_scope_kind']=='SOURCE_AREA' for r in additions),
        additional_informal_study_zones=sum(r['source_scope_kind']=='INFORMAL_STUDY_ZONE' for r in additions),
        value_cells=len(cells),reported_integer_cells=sum(type(v) is int for v in cells),
        original_dot_null_cells=cells.count(None),field_definitions=len(payload['fields']),
        arithmetic_passes=sum(c['status']=='passed' for c in payload['checks']),
        arithmetic_discrepancies=sum(c['status']=='source_discrepancy' for c in payload['checks']),
        arithmetic_skips=sum(c['status']=='skipped_original_missing_cells' for c in payload['checks']),
        excluded_reading_hold_placements=len(expected_excluded))
    if statistics != EXPECTED or len({r['record_key'] for r in payload['rows']}) != 42:
        raise ValueError('Expanded package statistics or record identities differ')
    if payload['rows'][:17] != prior['rows'] or payload['checks'][:len(prior['checks'])] != prior['checks']:
        raise ValueError('Prior release objects changed')
    payload['statistics'] = statistics
    return payload, {name:root/name for name in pins}


def manifest(files):
    return dict(family='historical-census-original-national-education',version=3,catalogue='32022',
        year=1961,publication_year=1964,source_key='census-original-education-32022-1961-c3a',
        original='original/'+ORIGINAL,original_sha256=ORIGINAL_SHA,
        source_url='https://censusindia.gov.in/nada/index.php/catalog/32022/download/35203/'+ORIGINAL,
        retrieved_at='2026-10-02T07:25:32.066512+00:00',
        prior_package_sha256=BASE_PACKAGE_SHA,prior_manifest_sha256=BASE_MANIFEST_SHA,
        prior_payload_sha256=BASE_PAYLOAD_SHA,mapping_sha256=MAPPING_SHA,
        boundary_basis='Original source-era headings and constituent dates; no modern boundary crosswalk',
        scope='C-III Part A All Areas:12 retained INDIA* ages plus30 complete non-national All ages source placements',
        files=files,statistics=EXPECTED,notes=NOTES,
        database_status='Prepared data only; compatible source-area/study-zone importer and transactional release required')


def build(root, output):
    if output.exists() or not output.parent.is_dir():
        raise ValueError('Output exists or destination absent')
    if min(shutil.disk_usage(root).free,shutil.disk_usage(output.parent).free) < 10*1024**3:
        raise ValueError('Disk reserve below10GiB')
    payload, proofs = load_reviewed(root)
    members = {('original/' if name==ORIGINAL else 'evidence/')+name:path for name,path in proofs.items()}
    files = {name:digest(path) for name,path in members.items()}
    raw = encoded(payload); files['education-population.json'] = hashlib.sha256(raw).hexdigest()
    with tempfile.NamedTemporaryFile(dir=output.parent,suffix='.zip',delete=False) as stream:
        temporary = Path(stream.name)
    try:
        with zipfile.ZipFile(temporary,'w',zipfile.ZIP_STORED) as archive:
            for name,path in members.items():
                with archive.open(zipfile.ZipInfo(name,(1980,1,1,0,0,0)),'w') as target,path.open('rb') as source:
                    shutil.copyfileobj(source,target,262144)
            archive.writestr(zipfile.ZipInfo('education-population.json',(1980,1,1,0,0,0)),raw)
            archive.writestr(zipfile.ZipInfo('manifest.json',(1980,1,1,0,0,0)),encoded(manifest(files)))
        verify(temporary,digest(temporary))
        with output.open('xb') as target,temporary.open('rb') as source:
            shutil.copyfileobj(source,target,262144)
    finally:
        temporary.unlink(missing_ok=True)
    with zipfile.ZipFile(output) as archive:
        manifest_sha = hashlib.sha256(archive.read('manifest.json')).hexdigest()
    return dict(package=output.name,sha256=digest(output),manifest_sha256=manifest_sha,
                bytes=output.stat().st_size,statistics=payload['statistics'],database_writes=0)


def verify(path, expected_sha):
    if shutil.disk_usage(tempfile.gettempdir()).free < 10*1024**3:
        raise ValueError('Verification disk reserve below10GiB')
    if digest(path) != expected_sha:
        raise ValueError('Package checksum differs')
    with zipfile.ZipFile(path) as archive,tempfile.TemporaryDirectory() as directory:
        names=archive.namelist();claimed=json.loads(archive.read('manifest.json'))
        if (len(names)!=len(set(names)) or set(names)!=set(claimed['files'])|{'manifest.json'}
                or any(name.startswith('/') or '..' in Path(name).parts or '\\' in name for name in names)
                or len({Path(name).name for name in names})!=len(names)):
            raise ValueError('Duplicate, unsafe or unexpected archive inventory')
        root=Path(directory)
        for name,expected in claimed['files'].items():
            if archive.getinfo(name).file_size>50_000_000:
                raise ValueError('Oversized archive member')
            with archive.open(name) as source,(root/Path(name).name).open('xb') as target:
                shutil.copyfileobj(source,target,262144)
            if digest(root/Path(name).name)!=expected:
                raise ValueError('Archive member checksum differs')
        payload,proofs=load_reviewed(root)
        exact={('original/' if name==ORIGINAL else 'evidence/')+name for name in proofs}|{'education-population.json','manifest.json'}
        if set(names)!=exact:
            raise ValueError('Unexpected archive inventory')
        if json.loads((root/'education-population.json').read_bytes())!=payload:
            raise ValueError('Mapped payload differs from pinned evidence')
        if claimed!=json.loads(encoded(manifest(claimed['files']))):
            raise ValueError('Manifest source, scope or statistics differ')
        return payload


if __name__=='__main__':
    parser=argparse.ArgumentParser(description=__doc__)
    parser.add_argument('root',type=Path);parser.add_argument('output',type=Path)
    args=parser.parse_args();prepare_prior(args.root)
    print(json.dumps(build(args.root,args.output),indent=2))
