"""Extract ECI's flat constituency/candidate workbooks with original cells retained."""
from extract_assembly_modern import load_cells,count,normal

EXPECTED = ['Constituency No.','Constituency Name','Candidate Name','Candidate Sex','Candidate Age','Candidate Category','Party Name','VALID VOTES POLLED in General','VALID VOTES POLLED in Postal','Total Valid Votes','Total Electors']


def extract(path,state):
    book=load_cells(path);records={};supported=0
    try:
        for sheet in book:
            rows=list(sheet.values)
            if not any(any(v is not None for v in r) for r in rows): continue
            headers=[(i,r) for i,r in enumerate(rows[:8]) if [normal(v) for v in r[:11]]==[normal(v) for v in EXPECTED]]
            if len(headers)!=1: raise ValueError('Unsupported or ambiguous flat-workbook headers')
            supported+=1;index,header=headers[0]
            total_label=str(header[11]).strip() if len(header)>11 and header[11] is not None else None
            for number,row in enumerate(rows[index+1:],index+2):
                if not any(v is not None for v in row): continue
                values=list(row)+[None]*max(0,13-len(row));code=count(values[0])
                if code is None or not isinstance(values[1],str) or not isinstance(values[2],str): raise ValueError('Non-candidate row in flat workbook: '+str(number))
                name=values[1].strip();record=records.setdefault(code,dict(code=code,name=name,state_name=state,number_of_seats=1,electors=count(values[10]),candidates=[],issues=[],reported_totals=[]))
                if normal(name)!=normal(record['name']): raise ValueError('One constituency code has conflicting names')
                if count(values[10])!=record['electors']: record['issues'].append('Elector figures differ between candidate rows.')
                total=dict(label=total_label,value=values[11])
                if total not in record['reported_totals']: record['reported_totals'].append(total)
                party=str(values[6] or '').strip();candidate_name=values[2].strip()
                record['candidates'].append(dict(candidate_name=candidate_name,sex=values[3],age=count(values[4]),category=values[5],party_at_election=party,general_votes=count(values[7]),postal_votes=count(values[8]),votes=count(values[9]),is_nota=party=='NOTA' and normal(candidate_name)=='noneoftheabove',source_sheet=sheet.title,workbook_row=number,source_values=list(row)))
        if supported!=1: raise ValueError('Expected one flat result sheet')
        for record in records.values():
            issues=['Candidate cells transcribed from the official workbook; independent summary reconciliation is pending.']+list(getattr(book,'reader_notes',[]))+record.pop('issues')
            cs=record['candidates'];keys=[(c['candidate_name'],c['party_at_election']) for c in cs]
            if len(set(keys))!=len(keys): issues.append('Duplicate candidate names and parties appear in the source; rows are retained.')
            if record['electors'] is None: issues.append('Elector total is missing or non-numeric.')
            if any(None in [c['general_votes'],c['postal_votes'],c['votes']] for c in cs): issues.append('Vote cells are missing, non-numeric or formulas; original cells are retained.')
            else:
                if any(c['general_votes']+c['postal_votes']!=c['votes'] for c in cs): issues.append('Candidate vote components differ from total votes.')
                total=sum(c['votes'] for c in cs)
                if record['electors'] is not None and total>record['electors']: issues.append('Candidate and NOTA votes exceed electors.')
                totals=record['reported_totals']
                if len(totals)!=1: issues.append('Reported table totals differ between candidate rows.')
                elif normal(totals[0]['label'])=='totalvalidvotespollednota':
                    if count(totals[0]['value'])!=total: issues.append('Candidate and NOTA sum differs from the reported table total.')
                    else: record['valid_candidate_votes']=sum(c['votes'] for c in cs if not c['is_nota'])
                else: issues.append('The source total column is preserved by its original label; voter and valid-vote meanings require summary verification.')
            record.update(status='needs_review',error='; '.join(dict.fromkeys(issues)))
        if not records: raise ValueError('No candidate rows found')
        return list(records.values())
    finally: book.close()
