"""Prepare the pinned INDIA* 1961 C-III-A age subset, preserving printed gaps.

Data preparation only: the Tripura and normalized 2001/2011 importers must not
admit this source. A separately tested national adapter is still required.
"""
import argparse
import copy
import hashlib
import json
import shutil
import tempfile
import zipfile
from datetime import datetime
from pathlib import Path

from build_original_education_package import digest, encoded

ORIGINAL = '22949_1961_SCT.pdf'
ORIGINAL_SHA = '30179b02a44bdd62baeebb1bb49b2676184f848110e8e140c153f3a2925009b8'
SOURCE_URL = 'https://censusindia.gov.in/nada/index.php/catalog/32022/download/35203/' + ORIGINAL
RETRIEVED = '2026-10-02T07:25:32.066512+00:00'
PINS = {
    'national-education-summary-verified-candidates-20261002T0913.json': '922f9aac2ca754e10c8c7b643022787e3aef68e7b503fa9446b494eb7e01aa20',
    'national-education-age-verified-candidates-20261002T0930.json': 'fd9b19f4065304a78e81046026c2ae0510cc21bc8846fec9c12a913022a9476e',
}
AGES = ['All ages', '0-4', '5-9', '10-14', '15-19', '20-24', '25-29', '30-34', '35-44', '45-59', '60+', 'Age not stated']
FIELDS = ['TOT_P', 'TOT_M', 'TOT_F', 'ILLITERATE_M', 'ILLITERATE_F', 'LITERATE_WITHOUT_LEVEL_M', 'LITERATE_WITHOUT_LEVEL_F', 'PRIMARY_JUNIOR_BASIC_M', 'PRIMARY_JUNIOR_BASIC_F', 'MATRIC_AND_ABOVE_M', 'MATRIC_AND_ABOVE_F']
LABELS = ['Total Population']*3 + ['Illiterate']*2 + ['Literate (without educational level)']*2 + ['Primary or Junior Basic']*2 + ['Matriculation and above']*2
NOTES = [
    'Original INDIA* C-III-A All Areas age rows only; not national geographical or topical completeness. States, informal study zones and Urban/Rural sub-tables remain outside this package.',
    'The source excludes only the NEFA portion where All-India schedules were not canvassed: 297853 persons, 147100 males and 150753 females. Do not add that footnote population to infer a total.',
    'C-III uses individual-slip population, distinct from C-I household sampling. Read and write with understanding defines literacy; highest written examination passed defines educational level.',
    'All ages includes ages 0-4, treated as illiterate by this table. No modern age-seven-plus rate or inferred persons count for education groups.',
    'Original dot marks remain NULL, not zero. Unresolved readings in other reviewed pages are excluded, not treated as official missing cells.',
    'Four printed arithmetic discrepancies are retained with explicit notes; no numeric repair or publisher correction inferred.',
    'National aggregate combines constituent enumeration periods, including Goa/Daman/Diu 1960-12-15 and Dadra/Nagar Haveli 1962-03-01. Volume census year 1961 is not a single constituent enumeration date.',
    'Original source-era geography retained; no modern LGD, Census-code or name-only crosswalk. Age labels belong to the source record identity.',
    'Reported per-1000 rates and footnote counts are retained in source evidence only, not mixed with person-count fields.',
]


def definitions():
    return {field: dict(original_label=label, sex=field.rsplit('_', 1)[1], unit='persons',
                       table='C-III Part A', residence='All Areas',
                       age_group='Original age label on each observation; All ages includes ages 0-4',
                       universe='INDIA* population subject to the documented NEFA portion exclusion',
                       missingness='Original dot notation only; unresolved readings are excluded')
            for field, label in zip(FIELDS, LABELS)}


def map_rows(records):
    """Admit complete original age rows, never unresolved-review placeholders."""
    if [r.get('original_age_group') for r in records] != AGES:
        raise ValueError('Original age inventory or ordering differs')
    rows = []
    for position, candidate in enumerate(records, 1):
        if (candidate.get('original_geography') != 'INDIA*' or candidate.get('original_table') != 'C-III Part A'
                or candidate.get('original_residence') != 'All Areas' or candidate.get('unit') != 'persons'
                or list(candidate.get('values', {})) != FIELDS or set(candidate.get('value_evidence', {})) != set(FIELDS)):
            raise ValueError('Original geography, table, unit or fields differ')
        missing = candidate.get('missing_cells', {})
        if set(missing) != {f for f, v in candidate['values'].items() if v is None}:
            raise ValueError('NULL lacks matching original missing-cell evidence')
        identity = f'32022:1961:C-III-A:115:1:INDIA*:All Areas:{AGES[position-1]}'
        row = dict(record_key=hashlib.sha256(identity.encode()).hexdigest(), source_record_identity=identity,
                   original_name='INDIA*', original_level='NATIONAL AGGREGATE', parent_original_name=None,
                   original_age_group=AGES[position-1], original_row_position_within_geography=position,
                   year=1961, table='C-III Part A', residence='All Areas',
                   values=copy.deepcopy(candidate['values']), value_evidence={}, flags=list(NOTES),
                   modern_LGD_identifiers=None, boundary_basis='Original 1961 source population; documented NEFA portion exclusion')
        for column, field in enumerate(FIELDS, 2):
            value = row['values'][field]
            original = candidate['value_evidence'][field]
            page = 115 if column <= 6 else 116
            if (original.get('source_column') != column or original.get('physical_page') != page
                    or original.get('printed_page') != page-7
                    or original.get('original_row_position_within_geography', 1) != position
                    or not isinstance(original.get('render_sha256'), str)
                    or len(original['render_sha256']) != 64):
                raise ValueError('Original cell locator differs')
            notation = missing.get(field, {}).get('original_mark')
            if value is None:
                if notation != '..' or not ((AGES[position-1] == '0-4' and column >= 7)
                                            or (AGES[position-1] == '5-9' and column >= 11)):
                    raise ValueError('Unreviewed NULL or missingness differs')
            elif type(value) is not int or value < 0:
                raise ValueError('Invalid reported integer count')
            row['value_evidence'][field] = dict(original, original_age_group=AGES[position-1],
                                               original_table=row['table'], missing_cell_notation=notation)
        rows.append(row)
    checks = reconcile(rows)
    for check in checks:
        if check['status'] == 'source_discrepancy':
            row = next(r for r in rows if r['original_age_group'] == check['age_group'])
            row['flags'].append(f"Printed discrepancy: {check['check']}; reported {check['reported']}, components {check['component_sum']}, difference {check['difference']}. Original counts retained.")
    return rows, checks


def reconcile(rows):
    checks = []
    def check(label, age, total, components):
        result = dict(check=label, age_group=age, reported=total, components=components)
        if total is None or any(v is None for v in components):
            result['status'] = 'skipped_original_missing_cells'
        else:
            result.update(component_sum=sum(components), difference=sum(components)-total,
                          status='passed' if sum(components) == total else 'source_discrepancy')
        checks.append(result)
    for row in rows:
        v, age = row['values'], row['original_age_group']
        check('Population persons = males + females', age, v['TOT_P'], [v['TOT_M'], v['TOT_F']])
        for sex in ['M', 'F']:
            check('Education groups = total '+sex, age, v['TOT_'+sex],
                  [v[g+'_'+sex] for g in ['ILLITERATE', 'LITERATE_WITHOUT_LEVEL', 'PRIMARY_JUNIOR_BASIC', 'MATRIC_AND_ABOVE']])
            if age == '0-4':
                check('Age 0-4 treated as illiterate '+sex, age, v['TOT_'+sex], [v['ILLITERATE_'+sex]])
    for field in FIELDS:
        check('Age rows sum to All ages: '+field, 'All ages', rows[0]['values'][field], [r['values'][field] for r in rows[1:]])
    return checks


def statistics(rows, checks):
    cells = [v for row in rows for v in row['values'].values()]
    return dict(source_age_rows=len(rows), value_cells=len(cells), reported_integer_cells=sum(type(v) is int for v in cells),
                original_dot_null_cells=cells.count(None), field_definitions=len(FIELDS),
                arithmetic_passes=sum(c['status']=='passed' for c in checks),
                arithmetic_discrepancies=sum(c['status']=='source_discrepancy' for c in checks),
                arithmetic_skips=sum(c['status']=='skipped_original_missing_cells' for c in checks))


def load_reviewed(root):
    documents = []
    for name, expected in PINS.items():
        if digest(root/name) != expected:
            raise ValueError('Pinned review checksum differs: '+name)
        document = json.loads((root/name).read_text(encoding='utf-8'))
        if (document.get('source_catalogue') != 32022 or document.get('original_sha256') != ORIGINAL_SHA
                or document.get('source_url') != SOURCE_URL or document.get('volume_census_year') != 1961
                or document.get('publication_year') != 1964
                or datetime.fromisoformat(document['retrieved_at']) != datetime.fromisoformat(RETRIEVED)):
            raise ValueError('Source provenance differs')
        documents.append(document)
    if digest(root/ORIGINAL) != ORIGINAL_SHA:
        raise ValueError('Original PDF checksum differs')
    with (root/ORIGINAL).open('rb') as stream:
        if stream.read(5) != b'%PDF-':
            raise ValueError('Original PDF signature differs')
    renders = {}
    for index, document in enumerate(documents):
        for page, expected in document['render_hashes'].items():
            name = f'india-1961-education-{page}.png' if index == 0 else f'india-1961-education-age-detail-{page}.png'
            if digest(root/name) != expected:
                raise ValueError('Reviewed render checksum differs')
            renders[name] = root/name
    if documents[1]['prior_all_ages_candidate_sha256'] != PINS[next(iter(PINS))]:
        raise ValueError('Age review references another All ages extraction')
    rows, checks = map_rows(documents[0]['population_records'] + documents[1]['population_records'])
    stats = statistics(rows, checks)
    expected = dict(source_age_rows=12, value_cells=132, reported_integer_cells=124, original_dot_null_cells=8,
                    field_definitions=11, arithmetic_passes=35, arithmetic_discrepancies=4, arithmetic_skips=10)
    if stats != expected or sorted(c['difference'] for c in checks if c['status']=='source_discrepancy') != [-5000,-1900,-1900,10000]:
        raise ValueError('Reviewed counts or printed discrepancy inventory differs')
    return dict(rows=rows, checks=checks, statistics=stats, fields=definitions(), notes=NOTES), renders


def manifest(files, statistics):
    return dict(family='historical-census-original-national-education', version=1, catalogue='32022', year=1961,
                publication_year=1964, source_key='census-original-education-32022-1961-c3a',
                original='original/'+ORIGINAL, original_sha256=ORIGINAL_SHA, source_url=SOURCE_URL,
                retrieved_at=RETRIEVED, boundary_basis='Original INDIA* source population with documented NEFA portion exclusion',
                scope='C-III Part A, INDIA* only, All Areas, All ages plus eleven original age groups; person-count columns 2-12',
                files=files, statistics=statistics, notes=NOTES, original_age_groups=AGES,
                database_status='Prepared data only; requires compatible national adapter and transactional import before live publication')


def build(root, output):
    if output.exists() or not output.parent.is_dir():
        raise ValueError('Output exists or destination is absent')
    if shutil.disk_usage(root).free < 10*1024**3 or shutil.disk_usage(output.parent).free < 10*1024**3:
        raise ValueError('Disk reserve below 10 GiB')
    payload, renders = load_reviewed(root)
    members = {'original/'+ORIGINAL:root/ORIGINAL, **{'evidence/'+n:root/n for n in PINS}, **{'evidence/'+n:p for n,p in renders.items()}}
    files = {name:digest(path) for name,path in members.items()}
    raw_payload = encoded(payload)
    files['education-population.json'] = hashlib.sha256(raw_payload).hexdigest()
    with tempfile.NamedTemporaryFile(dir=output.parent, suffix='.zip', delete=False) as stream:
        temporary = Path(stream.name)
    try:
        with zipfile.ZipFile(temporary, 'w', zipfile.ZIP_DEFLATED, compresslevel=1) as archive:
            for name, path in members.items():
                with archive.open(zipfile.ZipInfo(name, date_time=(1980,1,1,0,0,0)), 'w') as target, path.open('rb') as source:
                    shutil.copyfileobj(source,target,262144)
            for name, raw in [('education-population.json',raw_payload),('manifest.json',encoded(manifest(files,payload['statistics'])))]:
                archive.writestr(zipfile.ZipInfo(name,date_time=(1980,1,1,0,0,0)),raw)
        verify(temporary,digest(temporary))
        with output.open('xb') as target, temporary.open('rb') as source:
            shutil.copyfileobj(source,target,262144)
    finally:
        temporary.unlink(missing_ok=True)
    return dict(package=output.name,sha256=digest(output),bytes=output.stat().st_size,**payload['statistics'])


def verify(path, expected_sha):
    if digest(path) != expected_sha:
        raise ValueError('Package checksum differs')
    with zipfile.ZipFile(path) as archive, tempfile.TemporaryDirectory() as directory:
        names = archive.namelist()
        expected_names = {'manifest.json','education-population.json','original/'+ORIGINAL} | {'evidence/'+n for n in PINS} | {'evidence/india-1961-education-'+p+'.png' for p in ['99','100','115','116']} | {'evidence/india-1961-education-age-detail-'+p+'.png' for p in ['115','116']}
        if len(names) != len(set(names)) or set(names) != expected_names:
            raise ValueError('Duplicate, unsafe or unexpected archive inventory')
        claimed = json.loads(archive.read('manifest.json'))
        if set(claimed.get('files',{})) != expected_names-{'manifest.json'}:
            raise ValueError('Manifest inventory differs')
        root = Path(directory)
        for name, expected in claimed['files'].items():
            if archive.getinfo(name).file_size > 50_000_000:
                raise ValueError('Oversized archive member')
            with archive.open(name) as source, (root/Path(name).name).open('xb') as target:
                shutil.copyfileobj(source,target,262144)
            if digest(root/Path(name).name) != expected:
                raise ValueError('Member checksum differs')
        payload, _ = load_reviewed(root)
        if json.loads((root/'education-population.json').read_text(encoding='utf-8')) != payload:
            raise ValueError('Mapped payload or discrepancy notes differ from pinned evidence')
        if claimed != manifest(claimed['files'],payload['statistics']):
            raise ValueError('Manifest source, dates, scope or counts differ')
        return payload


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('root',type=Path)
    parser.add_argument('output',type=Path)
    args = parser.parse_args()
    print(json.dumps(build(args.root,args.output),indent=2))
