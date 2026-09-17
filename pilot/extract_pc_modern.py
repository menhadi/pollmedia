"""Whole-edition modern PC extraction, retaining NOTA and review status."""
import hashlib
import json
import re
from pathlib import Path

import fitz
import openpyxl
from extract_pc_legacy import normal


def pdf_records(path, year):
    records = {}; order = []; current_state=None
    with fitz.open(path) as doc:
        for page_index, page in enumerate(doc):
            text = page.get_text()
            if re.fullmatch(r'ST\d+\s+Page \d+\s*',text):continue
            if page_index==len(doc)-1 and 'INDIA TOTAL:' in text:continue
            identity = re.search(r'([^\n]+)\nConstituency:\s*(\d+)\s*\.\s*([^\n]+?)\s*\(\s*Total Electors\s+(\d+)\)', text)
            if not identity:
                if not order or 'Votes Secured' not in text or re.search(r'Constituency\s*:',text):raise ValueError(f'Unrecognized constituency on page {page_index+1}')
                records[order[-1]]['_texts'].append(text)
                continue
            state, code, seat, electors = identity.groups()
            if re.fullmatch(r'Page \d+',state,re.I):
                if current_state is None:raise ValueError('Constituency has no preceding state heading')
                state=current_state
            else:current_state=state
            key=(state,int(code))
            if key not in records:
                records[key]=dict(code=len(records)+1,state_name=state,official_pc_code=int(code),constituency_name=seat,name=f'{state} / {seat}',detail_page=page_index+1,electors=int(electors),number_of_seats=1,candidates=[],_texts=[])
                order.append(key)
            record=records[key]
            if record['electors']!=int(electors) or record['constituency_name']!=seat: raise ValueError('Conflicting repeated constituency identity')
            record['_texts'].append(text[identity.end():])
    for record in records.values():
        text='\n'.join(record.pop('_texts')); issues=[]
        # Every row has three vote components; symbols can span several lines.
        prefix=r'(?m)^(\d+)\n((?:(?!\n\d+\n).)+?)\n(?:(Male|Female|Third Gender|Third|Others)\n(\d+)\n([^\n]+)\n([^\n]+)\n(.+?)\n|(NOTA)\n(?:NOTA\n)?)'
        values=r'(\d+)\n(\d+)\n(\d+)\n(\d+)\n(\d+)\n([\d.]+)\n([\d.]+)\n(?:[\d.]+|-)\s*(?=\n|$)' if year==2024 else r'(\d+)\n(\d+)\n(\d+)\n([\d.]+)\n([\d.]+)\s*(?=\n|$)'
        for m in re.finditer(prefix+values,text,re.S):
            nota=m[8]=='NOTA'; start=11 if year==2024 else 9
            record['candidates'].append(dict(source_row=int(m[1]),candidate_name=' '.join(m[2].split()),party_at_election='NOTA' if nota else m[6],is_nota=nota,general_votes=int(m[start]),postal_votes=int(m[start+1]),votes=int(m[start+2])))
            if year==2024:
                if 'votes_polled' in record and (record['votes_polled'],record['valid_candidate_votes'])!=(int(m[9]),int(m[10])):issues.append('Repeated totals differ')
                record.update(votes_polled=int(m[9]),valid_candidate_votes=int(m[10]))
        cs=record['candidates']
        if sorted(c['source_row'] for c in cs)!=list(range(1,len(cs)+1)) or not cs: issues.append('Candidate serial numbers are missing or duplicated')
        if any(c['general_votes']+c['postal_votes']!=c['votes'] for c in cs):issues.append('Candidate components differ')
        totals=list(re.finditer(r'(?m)^TOTAL\n(?:-\n-\n)?(\d+)\n(\d+)\n(\d+)\n',text))
        if len(totals)!=1 or [sum(c[k] for c in cs) for k in ['general_votes','postal_votes','votes']] != [int(totals[0][i]) for i in [1,2,3]]:issues.append('Candidate table is incomplete or does not reconcile with the detailed total')
        if year==2024 and sum(c['votes'] for c in cs if not c['is_nota'])!=record.get('valid_candidate_votes'): issues.append('Candidate votes excluding NOTA differ from the reported valid total')
        issues.append('Independent summary totals have not yet been reconciled')
        record.update(status='needs_review',error='; '.join(dict.fromkeys(issues)))
    return [records[k] for k in order]


def xlsx_records(detail,summary,pdf):
    identities={}; summaries={}
    book=openpyxl.load_workbook(summary,read_only=True,data_only=False)
    try:
        for sheet in book:
            summary_rows=list(sheet.values)
            cells=summary_rows[1]
            state=re.fullmatch(r'(.+)-([SU]\d+)',cells[1]);seat=re.fullmatch(r'(.+)\s*-(\d+)',cells[3])
            if not state or not seat:raise ValueError('Summary workbook identity changed')
            key=(normal(state[1]),normal(seat[1]))
            if key in identities:raise ValueError('Duplicate summary identity')
            identities[key]=(state[2],int(seat[2]),state[1],seat[1].strip())
            summaries[key]=(sheet.title,summary_rows)
    finally:book.close()
    records={};book=openpyxl.load_workbook(detail,read_only=True,data_only=False);resolved={}
    try:
        for row_index,row in enumerate(book.active.values,1):
            if row_index<=3:continue
            if not any(v is not None for v in row):continue
            row=list(row)
            for column,value in enumerate(row,1):
                if isinstance(value,str) and value.startswith('='):
                    reference=re.fullmatch(r'=([A-Z]+)(\d+)',value)
                    if not reference or reference[1]+reference[2] not in resolved:raise ValueError('Unsupported workbook formula')
                    row[column-1]=resolved[reference[1]+reference[2]]
                resolved[openpyxl.utils.get_column_letter(column)+str(row_index)]=row[column-1]
            if len(row)!=14 or not row[0] or not row[1]:raise ValueError(f'Unrecognized workbook row {row_index}')
            key=(normal(row[0]),normal(row[1]));identity=identities.get(key)
            if not identity:raise ValueError(f'No unique summary identity for {key}')
            if key not in records:records[key]=dict(code=len(records)+1,state_name=row[0],state_code=identity[0],official_pc_code=identity[1],constituency_name=row[1],name=f'{row[0]} / {row[1]}',number_of_seats=1,electors=int(row[13]),candidates=[],status='needs_review',error='Independent summary totals have not yet been reconciled',source_locator=f'Sheet1, row {row_index}')
            record=records[key]
            if record['electors']!=int(row[13]):raise ValueError('Repeated elector totals differ')
            votes=[]
            for v in row[8:11]:
                if not isinstance(v,(int,float)) or v<0 or v!=int(v):raise ValueError('Invalid vote value or formula')
                votes.append(int(v))
            record['candidates'].append(dict(source_row=len(record['candidates'])+1,candidate_name=row[2],party_at_election=row[6],is_nota=row[6]=='NOTA',general_votes=votes[0],postal_votes=votes[1],votes=votes[2]))
            if votes[0]+votes[1]!=votes[2]:record['error']+='; Candidate vote components differ'
    finally:book.close()
    for key,identity in identities.items():
        if key not in records:
            records[key]=dict(code=len(records)+1,state_code=identity[0],state_name=identity[2],official_pc_code=identity[1],constituency_name=identity[3],name=f'{identity[2]} / {identity[3]}',number_of_seats=1,candidates=[],status='needs_review',error='This constituency is present in the summary workbook but missing from the detailed workbook')
    if len(records)!=543:raise ValueError(f'Expected 543 summary identities, found {len(records)}')
    with fitz.open(pdf) as doc:
        chunks=[];offsets=[];length=0
        for i,page in enumerate(doc):
            chunk='\n'.join(line.strip() for line in page.get_text().splitlines() if line.strip())
            offsets.append((length,i+1));chunks.append(chunk);length+=len(chunk)+1
    text='\n'.join(chunks)
    matches=list(re.finditer(r'(?m)^(\d+)\n([^\n]+)\nCONSTITUENCY :\n\.\n\( Total Electors\n\)\n(\d+)\n',text))
    if len(matches)!=543:raise ValueError('Detailed PDF identity coverage changed')
    for record in records.values():
        if record['candidates']:continue
        found=[(i,m) for i,m in enumerate(matches) if int(m[1])==record['official_pc_code'] and normal(m[2])==normal(record['constituency_name'])]
        if len(found)!=1:continue
        i,m=found[0];body=text[m.end():matches[i+1].start() if i+1<len(matches) else len(text)]
        total=re.search(r'(?m)^(\d+)\n(\d+)\nTOTAL:\n(\d+)\n',body)
        if not total:continue
        record.update(electors=int(m[3]),source_locator=f'Detailed PDF, page {max(n for offset,n in offsets if offset<=m.start())}',error='Detailed workbook omitted this table; recovered from the official PDF. Independent summary totals have not yet been reconciled')
        rows=body[:total.start()]
        pattern=r'(?m)^(\d+) ((?:(?!\n\d+ ).)+?)\n(?:([MFO])\n(\d+)\n(GEN|SC|ST)\n([^\d\n]+(?:\n[^\d\n]+)*?)|(?P<nota>NOTA))\n(\d+)\n(\d+)\n(\d+)\n[\d.]+\n[\d.]+'
        for row in re.finditer(pattern,rows,re.S):
            record['candidates'].append(dict(source_row=int(row[1]),candidate_name=' '.join(row[2].split()),party_at_election='NOTA' if row['nota'] else ''.join(row[6].splitlines()),is_nota=bool(row['nota']),postal_votes=int(row[8]),votes=int(row[9]),general_votes=int(row[10])))
        cs=record['candidates']
        if sorted(c['source_row'] for c in cs)!=list(range(1,len(cs)+1)) or [sum(c[k] for c in cs) for k in ['postal_votes','votes','general_votes']] != [int(total[n]) for n in [1,2,3]] or any(c['votes']!=c['general_votes']+c['postal_votes'] for c in cs):record['error']+='; PDF candidate rows need review'
    for key,record in records.items():
        title,rows=summaries[key]
        reconcile_2014(record,rows,title)
    return list(records.values())


def reconcile_2019(records, summary, year=2019):
    with fitz.open(summary) as doc:
        chunks=[];offsets=[];length=0
        for i,page in enumerate(doc):
            text=page.get_text();offsets.append((length,i+1));chunks.append(text);length+=len(text)+1
    text='\n'.join(chunks)
    if not re.search(r'ELECTIONS,\s*'+str(year)+r'\b',text[:500]):raise ValueError('Summary election year differs')
    pattern=r'STATE/UT:\s*([SU]\d+)\s+CODE:\s*\1\s+CONSTITUENCY\s*:\s*([^\n]+?)\s+(GEN|SC|ST)\s*\n(\d+)\s*\n'
    if year==2024:pattern=r'STATE/UT:\s*[^\n]+\nCODE:\s*([SU]\d+)\s+CONSTITUENCY\s*:\s*([^\n]+?)\s+(GEN|SC|ST)\s*\n(\d+)\s*\n'
    matches=list(re.finditer(pattern,text))
    if len(matches)!=len(records) or len({(m[1],int(m[4])) for m in matches})!=len(matches):raise ValueError('Detailed and summary edition coverage differs')
    state_codes={}
    for index,(record,match) in enumerate(zip(records,matches)):
        issues=[issue for issue in record.get('error','').split('; ') if issue and issue!='Independent summary totals have not yet been reconciled']
        record.pop('winner',None);record.pop('margin',None)
        if record['official_pc_code']!=int(match[4]) or normal(record['constituency_name'])!=normal(match[2]):
            record.update(status='needs_review',error='Detailed and summary constituency identity differs; summary figures were not attached')
            continue
        prior=state_codes.setdefault(record['state_name'],match[1])
        if prior!=match[1] or len(set(state_codes.values()))!=len(state_codes):raise ValueError('Summary state order differs from detailed report')
        record.update(state_code=match[1],summary_page=max(p for offset,p in offsets if offset<=match.start()))
        body=text[match.end():matches[index+1].start() if index+1<len(matches) else len(text)]
        def number(pattern):
            found=list(re.finditer(pattern,body))
            if len(found)!=1:raise ValueError('Summary field is missing or ambiguous')
            return int(found[0][1])
        try:
            electors=number(r'4\. TOTAL\s*\n\d+\s*\n\d+\s*\n\d+\s*\n(\d+)')
            contested=number(r'4\. CONTESTED\s*\n\d+\s*\n\d+\s*\n\d+\s*\n(\d+)')
            evm=number(r'1\. TOTAL VOTES POLLED ON EVM\s*\n(\d+)')
            postal=number(r'4\. POSTAL VOTES COUNTED\s*\n(\d+)')
            valid=number(r'7\. TOTAL VALID VOTES POLLED\s*\n(\d+)')
            nota=number(r"9\. VOTES POLLED FOR 'NOTA' \(INCLUDING POSTAL\)\s*\n(\d+)")
            record['summary_totals']=dict(electors=electors,votes_polled=evm+postal,valid_candidate_votes=valid)
            cs=record['candidates'];candidates=[c for c in cs if not c.get('is_nota')]
            if year==2024:
                if record.get('votes_polled')!=evm+postal:issues.append('Detailed and summary polled votes differ')
                if record.get('valid_candidate_votes')!=valid:issues.append('Detailed and summary valid votes differ')
            candidate_votes=sum(c['votes'] for c in candidates)
            if year==2019:record.update(votes_polled=evm+postal,valid_candidate_votes=candidate_votes)
            if record['electors']!=electors:issues.append('Elector totals differ from the summary')
            if len(candidates)!=contested or sum(c.get('is_nota',False) for c in cs)!=1:issues.append('Candidate or NOTA row counts differ from the summary')
            if candidate_votes!=valid:issues.append('Candidate votes differ from summary valid votes')
            if sum(c['votes'] for c in cs if c.get('is_nota'))!=nota:issues.append('NOTA votes differ from the summary')
            if not 0<valid+nota<=evm+postal<=electors:issues.append('Summary elector and vote totals do not reconcile')
            ranked=sorted(candidates,key=lambda c:c['votes'],reverse=True)
            result=re.search(r'\nWINNER\s*\n(.+?)\n(\d+)\s*\nRUNER-UP\s*\n(.+?)\n(\d+)\s*\nMARGIN\s*\n(\d+)',body,re.S)
            if not result or len(ranked)<2 or ranked[0]['votes']==ranked[1]['votes']:issues.append('Summary winner requires review')
            else:
                if ranked[0]['votes']!=int(result[2]) or ranked[1]['votes']!=int(result[4]) or normal(ranked[0]['candidate_name']) not in normal(result[1]) or normal(ranked[1]['candidate_name']) not in normal(result[3]):issues.append('Winner or runner-up differs from the summary')
                margin=ranked[0]['votes']-ranked[1]['votes']
                if margin!=int(result[5]):issues.append('Winning margin differs from the summary')
                if not issues:record.update(winner=ranked[0]['candidate_name'],margin=margin)
        except ValueError as error:issues.append(str(error))
        if issues:record.update(status='needs_review',error='; '.join(dict.fromkeys(issues)))
        else:record['status']='validated';record.pop('error',None)
    return records


def reconcile_2014(record, rows, sheet_name):
    """Compare independently reported totals; never turn source discrepancies into corrections."""
    def cell(row, column, label):
        if len(rows)<row or len(rows[row-1])<=column or str(rows[row-1][1]).strip().casefold()!=label.casefold():
            raise ValueError('Summary labels changed: '+label)
        value=rows[row-1][column]
        if isinstance(value,bool) or not isinstance(value,(int,float)) or value<0 or int(value)!=value:
            raise ValueError('Missing, invalid or formula-based summary value: '+label)
        return int(value)
    original=record.get('error','')
    issues=[]
    if 'PDF candidate rows need review' in original:issues.append('PDF candidate rows need review')
    if 'Candidate vote components differ' in original:issues.append('Candidate vote components differ')
    if 'recovered from the official PDF' in original:record['extraction_note']='Detailed workbook omitted this table; recovered from the official PDF'
    record.pop('winner',None);record.pop('margin',None)
    try:
        def column(row,label):
            found=[i for i,value in enumerate(rows[row-1]) if str(value).strip().casefold()==label.casefold()]
            if len(found)!=1:raise ValueError('Summary column is missing or ambiguous: '+label)
            return found[0]
        total_column=column(3,'Total');vote_column=column(39,'Votes');name_column=column(39,'Candidates');party_column=column(39,'Party')
        totals=dict(electors=cell(13,total_column,'Total'),votes_polled=cell(19,total_column,'Total'),valid_candidate_votes=cell(29,total_column,'Total Valid Votes Polled'))
        nota=cell(31,total_column,"Votes Polled for 'NOTA'(Including Postal)")
        contested=cell(7,total_column,'Contested')
        record['summary_totals']=totals
        col=openpyxl.utils.get_column_letter(total_column+1);vcol=openpyxl.utils.get_column_letter(vote_column+1)
        record['summary_locator']=f'{sheet_name}: {col}7, {col}13, {col}19, {col}29, {col}31, {vcol}40/{vcol}41, D42'
        record['votes_polled']=totals['votes_polled']
        cs=record['candidates'];candidates=[c for c in cs if not c.get('is_nota')]
        record['valid_candidate_votes']=sum(c['votes'] for c in candidates)
        if record.get('electors')!=totals['electors']:issues.append('Detailed and summary elector totals differ')
        if len(candidates)!=contested or len([c for c in cs if c.get('is_nota')])!=1:issues.append('Candidate or NOTA row counts differ from the summary')
        if len({(normal(c['candidate_name']),normal(c['party_at_election'])) for c in cs})!=len(cs):issues.append('Duplicate candidate identities require review')
        if sum(c['votes'] for c in cs if c.get('is_nota'))!=nota:issues.append('NOTA votes differ from the summary')
        if record['valid_candidate_votes']!=totals['valid_candidate_votes']:issues.append('Candidate votes differ from summary valid votes')
        if not 0<record['valid_candidate_votes']+nota<=totals['votes_polled']<=totals['electors']:issues.append('Valid votes, NOTA, polled votes and electors do not reconcile')
        ranked=sorted(candidates,key=lambda c:c['votes'],reverse=True)
        if len(ranked)<2 or ranked[0]['votes']==ranked[1]['votes']:issues.append('Winner requires review')
        else:
            for rank,row,label in [(0,40,'Winner'),(1,41,'Runner-Up')]:
                votes=cell(row,vote_column,label)
                if ranked[rank]['votes']!=votes or normal(ranked[rank]['candidate_name'])!=normal(str(rows[row-1][name_column])) or normal(ranked[rank]['party_at_election'])!=normal(str(rows[row-1][party_column])):issues.append(label+' differs from the official summary')
            margin=cell(42,3,'Margin')
            if ranked[0]['votes']-ranked[1]['votes']!=margin:issues.append('Winning margin differs from the summary')
            if not issues:record.update(winner=ranked[0]['candidate_name'],margin=margin)
    except ValueError as error:issues.append(str(error))
    if issues:record.update(status='needs_review',error='; '.join(dict.fromkeys(issues)))
    else:record['status']='validated';record.pop('error',None)


def run(manifest_path):
    path=Path(manifest_path);m=json.loads(path.read_text(encoding='utf-8'));year=m['year']
    if m['kind']!='pc' or year not in [2014,2019,2024]:raise ValueError('Unsupported modern edition')
    if year==2014:
        names=['97f53be05d08ca1e1d50c7a4-6464.xlsx','51512f85716a7b6349923689-6469.xlsx','97f53be05d08ca1e1d50c7a4-6463.pdf']
    else:
        names=['349e04305ee4652986f79497-saved.pdf'] if year==2024 else (['85be7b21fd43938b468f6f83-37787.pdf'] if any(f['file']=='85be7b21fd43938b468f6f83-37787.pdf' for f in m['files']) else ['44abcc4f98a9130d7b8eed2d-30003.pdf'])
        if year==2019:names.append('2153706307f65ea1628b2ba3-37789.pdf' if names[0].startswith('85be') else '001feea54c10b86f6969e32e-30005.pdf')
        if year==2024:names.append('349e04305ee4652986f79497-summary.pdf')
    sources=[]
    for name in names:
        found=[f for f in m['files'] if f['file']==name]
        if len(found)!=1:raise ValueError('Required source file missing')
        source=found[0]
        if hashlib.sha256((path.parent/name).read_bytes()).hexdigest()!=source['sha256']:raise ValueError('Source checksum differs')
        sources.append(source)
    records=xlsx_records(*(path.parent/f['file'] for f in sources)) if year==2014 else pdf_records(path.parent/names[0],year)
    if year in [2019,2024]:records=reconcile_2019(records,path.parent/names[1],year)
    populated=sum(bool(r['candidates']) for r in records)
    validated=sum(r['status']=='validated' for r in records)
    result=dict(adapter='eci-pc-modern-v2',kind='pc',year=year,source_url=m['url'],source_file=names[0],source_sha256=sources[0]['sha256'],additional_sources=sources[1:],records=records,validated_count=validated,review_count=len(records)-validated,coverage_note=f'{m["label"]}: {len(records)} constituency identities, {populated} with extracted rows. NOTA is retained separately from candidates. Dagger notes identify pending checks. Historical mapping and publication are pending.')
    temporary=path.parent/'extraction.tmp';temporary.write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8');temporary.replace(path.parent/'extraction.json')
    return result


if __name__=='__main__':
    import argparse
    parser=argparse.ArgumentParser();parser.add_argument('manifest');args=parser.parse_args();r=run(args.manifest);print(json.dumps({k:r[k] for k in ['year','validated_count','review_count']}))
