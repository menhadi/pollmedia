"""Build the pinned Kerala 1961 C-III package; no database writes or inferred cells."""
import argparse
import copy
import hashlib
import json
import shutil
import tempfile
import zipfile
from pathlib import Path

ORIGINAL = '23004_1961_CUI.pdf'
ORIGINAL_SHA = 'd664e9cd580851296bab6bf45e09f633c28d5c4f7a428184a72805d7ea137720'
URL = 'https://censusindia.gov.in/nada/index.php/catalog/28296/download/31478/' + ORIGINAL
RETRIEVED = '2026-10-02T07:26:24.622474+00:00'
CANDIDATE = 'kerala-original-education-urban-candidates-v20-20261002T1732.json'
INVENTORY = 'kerala-package-evidence-inventory-20261002T1748.json'
PINS = {
    CANDIDATE: 'fa10bb452387282d82a8e81c8020c83a8d564af8a28dc3e159a72c1fcd1a832e',
    INVENTORY: 'a867ce051ac78dc5ad15fcde4f34296433cc508b6dd64096f03ffaa57ede25c9',
    'kerala-c3-coverage-audit-20261002T1732.json': '9fd90d8a9f622e16a862bd9ffdd250266d4d55cc04bc9416fc08c3768a40ce5a',
    'kerala-teaching-source-review-20261002T1732.json': 'ed5c68cf1e521a41b3fea57c537be37071f2d3402c21dfa705bb0ce3fac6170e',
    'kerala-final-urban-age-validation-20261002T1732.json': 'a60a835d583f62cb3d21d0266d4d172e88c51e3413699435cc7a03ff67bf0adc',
}
AGES = ['All ages', '0-4', '5-9', '10-14', '15-19', '20-24', '25-29', '30-34', '35-44', '45-59', '60+', 'Age not stated']
BLOCKS = [(43,48,56,1,'KERALA STATE'), (43,48,56,2,'CANNANORE DISTRICT'), (43,48,56,3,'KOZHIKODE DISTRICT'), (44,50,57,1,'PALGHAT DISTRICT'), (44,50,57,2,'TRICHUR DISTRICT'), (44,50,57,3,'ERNAKULAM DISTRICT'), (45,52,58,1,'KOTTAYAM DISTRICT'), (45,52,58,2,'ALLEPPEY DISTRICT'), (45,52,58,3,'QUILON DISTRICT'), (46,54,59,1,'TRIVANDRUM DISTRICT')]
COLUMNS = ['TOT_P','TOT_M','TOT_F','ILL_M','ILL_F','LIT_NOLEVEL_M','LIT_NOLEVEL_F','PRIMARY_M','PRIMARY_F','MATRIC_M','MATRIC_F','TECH_DIPLOMA_M','TECH_DIPLOMA_F','NONTECH_DIPLOMA_M','NONTECH_DIPLOMA_F','NONTECH_DEGREE_M','NONTECH_DEGREE_F','ENGINEERING_M','ENGINEERING_F','MEDICINE_M','MEDICINE_F','AGRICULTURE_M','AGRICULTURE_F','VETERINARY_DAIRYING_M','VETERINARY_DAIRYING_F','TECHNOLOGY_M','TECHNOLOGY_F','TEACHING_M','TEACHING_F','OTHERS_M','OTHERS_F']
LABELS = ['Total Population']*3 + ['Illiterate']*2 + ['Literate (without educational level)']*2 + ['Primary or Junior Basic']*2 + ['Matriculation or Higher Secondary']*2 + ['Technical diploma not equal to degree']*2 + ['Non-technical diploma not equal to degree']*2 + ['University degree or post-graduate degree other than technical degree']*2 + ['Engineering','Engineering','Medicine','Medicine','Agriculture','Agriculture','Veterinary and dairying','Veterinary and dairying','Technology','Technology','Teaching','Teaching','Others','Others']
NOTES = [
    'Original Kerala 1961 C-III only: state and nine original districts, source age groups and All Areas/Urban/Rural residences; publication year 1965. Not national or all-topic completeness.',
    '359 complete rows retained:120 Urban,120 Rural,119 All Areas. Damaged All Areas state30-34 Primary_M row is excluded in full; no subtraction or residual filling.',
    'Read and write a simple letter with understanding defines literacy; highest passed written examination defines educational level. Source flyleaf physical42/printed26 is retained.',
    'All Areas/Rural Matriculation and above differs from Urban Matriculation or Higher Secondary and its separate diploma/degree fields. Distinct field keys prevent merging these classifications.',
    'Urban technical degree branch columns are disjoint entries, counted once; parent heading is not an extra total. Medical/teaching graduates are education counts, not facilities or service availability.',
    'Original dots remain NULL. No age-seven-plus rates, enrolment, health/amenity measures or unreported person counts inferred.',
    'Official errata physical2 corrects Urban Allages TOT_M1282579 to1282759 and Rural Quilon Age not stated MATRIC_F3 to8; raw and effective cells and exact authority locators retained.',
    'Printed discrepancies retained: Urban state Allages PRIMARY_F133228 vs district183228; state30-34 LIT_NOLEVEL_F29533 vs district29583; state25-29 TEACHING_F474 vs district473.',
    'Original source-era identities only. Storage record keys include source table/page/block/residence/age; no modern LGD, Census-code, retrospective boundary or names-only joins.',
]


def digest(path):
    h=hashlib.sha256()
    with path.open('rb') as stream:
        for block in iter(lambda:stream.read(262144),b''): h.update(block)
    return h.hexdigest()


def encoded(value):
    return (json.dumps(value,ensure_ascii=False,indent=2)+'\n').encode('utf-8')


def field_key(field, part):
    if field.startswith('MATRIC_'):
        return ('MATRIC_HIGHER_SECONDARY_' if part=='B' else 'MATRIC_AND_ABOVE_')+field[-1]
    return field


def definitions():
    result={}
    for part in 'ABC':
        for field,label in zip(COLUMNS if part=='B' else COLUMNS[:11],LABELS):
            if field.startswith('MATRIC_') and part!='B': label='Matriculation and above'
            key=field_key(field,part)
            result[key]=dict(original_label=label,sex=field[-1],unit='persons',
                table='C-III Part B' if part=='B' and COLUMNS.index(field)>=9 else 'C-III A/B/C source population/education columns',
                residence_scheme='Urban only' if key not in COLUMNS[:9] and part=='B' else 'All Areas and Rural' if key.startswith('MATRIC_AND_ABOVE') else 'All Areas/Urban/Rural',
                universe='Original age label on each row; All ages includes ages0-4',missingness='Printed dots only; unresolved damaged rows excluded',parent_field=None)
    return result


def map_rows(document, inventory):
    source=document['source']
    if source['catalogue']!='28296' or source['url']!=URL or source['sha256']!=ORIGINAL_SHA or source['retrieved_at']!=RETRIEVED or source['census_year']!=1961 or source['publication_year']!=1965:
        raise ValueError('Original provenance differs')
    candidates={r['source_placement_key']:r for r in document['rows']}
    if len(candidates)!=len(document['rows']): raise ValueError('Duplicate source placement')
    rows=[];expected=set();corrections=[]
    for ap,bp,cp,block,geography in BLOCKS:
        for part,page,residence in zip('ABC',[ap,bp,cp],['All Areas','Urban','Rural']):
            for position,age in enumerate(AGES,1):
                placement=f'28296:C-III-{part}:{page}:{block}:{age}';expected.add(placement)
                if placement=='28296:C-III-A:43:1:30-34':
                    if placement in candidates: raise ValueError('Damaged partial must remain excluded')
                    continue
                r=candidates.get(placement);original_name='KERALA' if part=='A' and geography=='KERALA STATE' else geography
                if not r or r['residence']!=residence or r['age']!=age or r['geography']!=original_name or r['physical_pages']!=([page,page+1] if part=='B' else [page]): raise ValueError('Source age, geography, residence or page differs')
                columns=COLUMNS if part=='B' else COLUMNS[:11]
                if set(r['original_printed'])!=set(columns) or set(r['effective_review_values'])!=set(columns) or r.get('unresolved_visual_cells'): raise ValueError('Incomplete or unresolved source cells')
                if set(r['printed_missingness'])!={f for f,v in r['original_printed'].items() if v is None}: raise ValueError('NULL lacks original dot evidence')
                identity=f'28296:1961:C-III-{part}:{page}:{block}:{original_name}:{residence}:{age}'
                row=dict(record_key=hashlib.sha256(identity.encode()).hexdigest(),source_record_identity=identity,original_name=original_name,original_level='STATE' if geography=='KERALA STATE' else 'DISTRICT',parent_original_name=None if geography=='KERALA STATE' else ('KERALA' if part=='A' else 'KERALA STATE'),original_age_group=age,original_row_position_within_geography=position,original_block_ordinal=block,year=1961,table='C-III Part '+part,residence=residence,values={},value_evidence={},flags=list(NOTES),modern_LGD_identifiers=None)
                for field in columns:
                    raw=r['original_printed'][field];value=r['effective_review_values'][field];correction=r.get('official_corrections',{}).get(field)
                    if raw is not None and (type(raw) is not int or raw<0): raise ValueError('Invalid original integer')
                    if raw is None and r['printed_missingness'][field]!='..': raise ValueError('Missingness is not a printed dot')
                    if correction:
                        authority=(placement,field,raw,value,correction['authority_physical_page'],correction['authority_printed_target_page'],correction['authority_target_column'])
                        corrections.append(authority)
                        if correction['raw']!=raw or correction['corrected']!=value: raise ValueError('Errata original/effective mismatch')
                    elif value!=raw: raise ValueError('Unapproved arithmetic repair')
                    key=field_key(field,part);column=COLUMNS.index(field)+2;physical=page+1 if part=='B' and column>=13 else page
                    row['values'][key]=value
                    render=inventory['pages'][str(physical)]
                    row['value_evidence'][key]=dict(original_table=row['table'],source_column=column,physical_page=physical,printed_page=physical-16,original_block_ordinal=block,original_age_group=age,original_row_position_within_geography=position,original_printed_value=raw,missing_cell_notation=r['printed_missingness'].get(field),official_correction=copy.deepcopy(correction),render_filename=render,render_sha256=inventory['files'][render])
                rows.append(row)
    if set(candidates)!=expected-{'28296:C-III-A:43:1:30-34'} or sorted(corrections)!=sorted([('28296:C-III-B:48:1:All ages','TOT_M',1282579,1282759,2,32,3),('28296:C-III-C:58:3:Age not stated','MATRIC_F',3,8,2,42,12)]): raise ValueError('Exact coverage or official errata inventory differs')
    cells=[v for row in rows for v in row['values'].values()]
    stats=dict(source_age_residence_rows=len(rows),value_cells=len(cells),reported_integer_cells=sum(type(v) is int for v in cells),original_dot_null_cells=cells.count(None),field_definitions=len(definitions()),official_errata_effective_cells=len(corrections),excluded_damaged_partial_rows=1,printed_source_discrepancies=3)
    if stats!=dict(source_age_residence_rows=359,value_cells=6349,reported_integer_cells=4769,original_dot_null_cells=1580,field_definitions=33,official_errata_effective_cells=2,excluded_damaged_partial_rows=1,printed_source_discrepancies=3): raise ValueError('Reviewed cell counts differ')
    return rows,stats


def load_reviewed(root):
    docs={}
    for name,expected in PINS.items():
        if digest(root/name)!=expected: raise ValueError('Pinned evidence checksum differs: '+name)
        docs[name]=json.loads((root/name).read_text(encoding='utf-8'))
    if digest(root/ORIGINAL)!=ORIGINAL_SHA or (root/ORIGINAL).read_bytes()[:5]!=b'%PDF-': raise ValueError('Original PDF differs')
    inventory=docs[INVENTORY]
    for name,expected in inventory['files'].items():
        if Path(name).name!=name or digest(root/name)!=expected: raise ValueError('Page/cell evidence differs: '+name)
    rows,stats=map_rows(docs[CANDIDATE],inventory)
    audit=docs['kerala-c3-coverage-audit-20261002T1732.json'];teaching=docs['kerala-teaching-source-review-20261002T1732.json']
    if len(audit['flags'])!=3 or teaching['state_reported']!=474 or teaching['nine_district_sum']!=473 or teaching['values_changed']!=0: raise ValueError('Printed discrepancy review differs')
    payload=dict(rows=rows,fields=definitions(),statistics=stats,notes=NOTES,checks=audit['checks'],source_discrepancies=audit['flags'],source_discrepancy_review=teaching,exclusions=[dict(placement='28296:C-III-A:43:1:30-34',reason='Damaged Primary_M cell; whole incomplete row excluded')],definitions_verified=docs[CANDIDATE]['definitions_verified'])
    return payload,inventory


def manifest(files,stats):
    return dict(family='historical-census-original-kerala-education',version=1,catalogue='28296',year=1961,publication_year=1965,source_key='census-original-education-28296-1961-c3',original='original/'+ORIGINAL,original_sha256=ORIGINAL_SHA,source_url=URL,retrieved_at=RETRIEVED,boundary_basis='Original1961 Kerala state/district and residence labels; no modern geography mapping',scope='C-III A/B/C:359 complete source age/residence rows; one damaged All Areas row excluded',files=files,statistics=stats,notes=NOTES,original_age_groups=AGES,database_status='Prepared data only; compatible pinned Kerala adapter and transactional import required')


def build(root,output):
    if output.exists() or not output.parent.is_dir(): raise ValueError('Output exists or destination absent')
    if min(shutil.disk_usage(root).free,shutil.disk_usage(output.parent).free)<10*1024**3: raise ValueError('Disk reserve below10GiB')
    payload,inventory=load_reviewed(root)
    members={'original/'+ORIGINAL:root/ORIGINAL,**{'evidence/'+n:root/n for n in PINS},**{'evidence/'+n:root/n for n in inventory['files']}}
    files={n:digest(p) for n,p in members.items()};raw=encoded(payload);files['education-population.json']=hashlib.sha256(raw).hexdigest()
    with tempfile.NamedTemporaryFile(dir=output.parent,suffix='.zip',delete=False) as f:temporary=Path(f.name)
    try:
        with zipfile.ZipFile(temporary,'w',zipfile.ZIP_DEFLATED,compresslevel=1) as z:
            for name,path in members.items():
                with z.open(zipfile.ZipInfo(name,date_time=(1980,1,1,0,0,0)),'w') as target,path.open('rb') as source:shutil.copyfileobj(source,target,262144)
            z.writestr(zipfile.ZipInfo('education-population.json',date_time=(1980,1,1,0,0,0)),raw)
            z.writestr(zipfile.ZipInfo('manifest.json',date_time=(1980,1,1,0,0,0)),encoded(manifest(files,payload['statistics'])))
        verify(temporary,digest(temporary))
        with output.open('xb') as target,temporary.open('rb') as source:shutil.copyfileobj(source,target,262144)
    finally:temporary.unlink(missing_ok=True)
    return dict(package=output.name,sha256=digest(output),bytes=output.stat().st_size,manifest_sha256=hashlib.sha256(encoded(manifest(files,payload['statistics']))).hexdigest(),payload_sha256=files['education-population.json'],statistics=payload['statistics'],database_writes=0)


def verify(path,expected):
    if digest(path)!=expected: raise ValueError('Package checksum differs')
    with zipfile.ZipFile(path) as z,tempfile.TemporaryDirectory() as directory:
        names=z.namelist();root=Path(directory);claimed=json.loads(z.read('manifest.json'))
        if len(names)!=len(set(names)) or set(names)!={'manifest.json'}|set(claimed['files']): raise ValueError('Archive inventory differs')
        for name,sha in claimed['files'].items():
            if name!='education-population.json' and not (name.startswith('original/') or name.startswith('evidence/')): raise ValueError('Unsafe member')
            if Path(name).name in ['','..'] or name.count('/')>1 or z.getinfo(name).file_size>50_000_000: raise ValueError('Unsafe or oversized member')
            target=root/Path(name).name
            with z.open(name) as source,target.open('xb') as stream:shutil.copyfileobj(source,stream,262144)
            if digest(target)!=sha: raise ValueError('Archive member checksum differs')
        payload,inventory=load_reviewed(root)
        expected_names={'manifest.json','education-population.json','original/'+ORIGINAL}|{'evidence/'+n for n in PINS}|{'evidence/'+n for n in inventory['files']}
        if set(names)!=expected_names or json.loads((root/'education-population.json').read_text(encoding='utf-8'))!=payload or claimed!=manifest(claimed['files'],payload['statistics']): raise ValueError('Mapped payload or manifest differs from pinned originals')
        return payload


if __name__=='__main__':
    p=argparse.ArgumentParser(description=__doc__);p.add_argument('root',type=Path);p.add_argument('output',type=Path);a=p.parse_args()
    print(json.dumps(build(a.root,a.output),indent=2))
