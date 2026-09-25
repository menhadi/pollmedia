"""Compare staged raw cells/types with preserved originals; profile without accepting joins."""
from collections import Counter
import json
import zipfile
from ocr_civic_pdf_pages import ResourceWait


def validate(root, package, expected, db):
    from census_server_worker import digest, workbook_rows, value_json, resources_ok
    if digest(package) != expected: raise ValueError('Package checksum mismatch')
    with zipfile.ZipFile(package) as archive:
        sources = json.loads(archive.read('manifest.json'))['sources']
    report = {'package_sha256':expected,'review_state':'raw_cells_verified_not_accepted_indicators','sources':[]}
    for item in sources:
        h=item['sha256']
        saved=db.execute('select file,status,row_count from workbooks where sha256=?',(h,)).fetchone()
        if not saved or saved[1]!='complete': raise ValueError('Raw workbook extraction must complete first')
        original=root/saved[0]
        if digest(original)!=h: raise ValueError('Original checksum mismatch')
        profiles={};seen={};checked=0
        for sheet,number,values,types in workbook_rows(original):
            canonical=json.loads(json.dumps(values,default=value_json,allow_nan=False))
            source_types=json.loads(json.dumps(list(types)))
            stored=db.execute('select cells_json,cell_types_json from raw_rows where workbook_sha256=? and sheet=? and source_row=?',(h,sheet,number)).fetchone()
            if not stored or json.loads(stored[0])!=canonical or json.loads(stored[1])!=source_types:
                raise ValueError('Raw cells/types mismatch at '+sheet+':'+str(number))
            checked+=1
            profile=profiles.setdefault(sheet,{'raw_rows':0,'header_row':None,'reference_years':Counter(),'duplicate_identifiers':0,'missing_identifiers':0})
            profile['raw_rows']+=1
            labels=[str(x).strip().lower() if x is not None else '' for x in canonical]
            if profile['header_row'] is None and number<=10 and 'state code' in labels and any(x in labels for x in ['village code','town code']):
                profile['header_row']=number
                profile['identifier_column']=labels.index('village code' if 'village code' in labels else 'town code')
                profile['reference_year_column']=labels.index('reference year') if 'reference year' in labels else None
                seen[sheet]=set()
            elif profile['header_row'] is not None:
                col=profile['identifier_column'];identifier=canonical[col] if col<len(canonical) else None
                token=json.dumps(identifier)
                if identifier in (None,''):profile['missing_identifiers']+=1
                elif token in seen[sheet]:profile['duplicate_identifiers']+=1
                else:seen[sheet].add(token)
                year=profile['reference_year_column']
                if year is not None:profile['reference_years'][json.dumps(canonical[year] if year<len(canonical) else None)]+=1
            if checked%1000==0 and not resources_ok(root):raise ResourceWait('Waiting for disk/RAM reserve')
        count=db.execute('select count(*) from raw_rows where workbook_sha256=?',(h,)).fetchone()[0]
        if checked!=count or checked!=saved[2]:raise ValueError('Raw row count mismatch')
        report['sources'].append(dict(source_key=item['key'],source_url=item['source_url'],original_sha256=h,rows_checked=checked,sheets=profiles))
    report['warning']='Headers/notes are included. Repeated codes may represent legitimate detail rows. Reference years are source values; no jurisdiction or indicator is accepted by this check.'
    path=root/'source-evidence'/('workbook-validation-'+expected+'.json');path.parent.mkdir(exist_ok=True)
    temp=path.with_suffix('.tmp');temp.write_text(json.dumps(report,indent=2));temp.replace(path)
    return report
