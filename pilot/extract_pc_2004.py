"""2004 national Lok Sabha detailed and summary PDF extraction."""
import argparse
import json
import re
import fitz
from extract_pc_2009 import run
from extract_state_election_2002 import summary_number


def extract(detail, summary):
    with fitz.open(detail) as detailed, fitz.open(summary) as summaries:
        ids=[]
        for i,page in enumerate(summaries):
            text='\n'.join(line.strip() for line in page.get_text().splitlines() if line.strip())
            match=re.search(r'([^\n]+)\nSTATE/UT :\nCONSTITUENCY :\n([^\n]+)\nCODE :\nNO :\n([SU]\d+)\n(\d+)',text)
            if match:ids.append((match[3],int(match[4]),match[2],match[1],i+1,page))
        if len(ids)!=543 or len({(s,c) for s,c,n,state,i,p in ids})!=543:raise ValueError(f'Expected 543 unique summaries; found {len(ids)}')
        states={r[3] for r in ids} | {'ANDAMAN & NICOBAR ISLANDS', 'NATIONAL CAPITAL TERRITORY OF DELHI'}
        headers={'No.','CANDIDATES','SEX','PARTY','GENERAL ELECTIONS - INDIA, 2004','DETAILED RESULTS','AGE','CATEGORY','VALID VOTES POLLED','TOTAL','GENERAL','POSTAL'} | states
        chunks=[];offsets=[];length=0;report_pages=[]
        for i,page in enumerate(detailed):
            text=page.get_text();footer=re.search(r'rptDetailedResults - (\d+) of\s+170',text)
            if not footer:continue
            report_pages.append(int(footer[1]))
            cleaned='\n'.join(line.strip() for line in text[:footer.start()].splitlines() if line.strip() and line.strip() not in headers)
            offsets.append((length,i+1));chunks.append(cleaned);length+=len(cleaned)+1
        if report_pages!=list(range(1,171)):raise ValueError('Detailed source pages incomplete')
        text='\n'.join(chunks)
        matches=list(re.finditer(r'Constituency\s*:\s*(\d+)\s*\.\s*([^\n]+)\n',text))
        if len(matches)!=543:raise ValueError(f'Expected 543 detailed constituencies; found {len(matches)}')
        normal=lambda value:re.sub(r'[^a-z0-9]','',value.lower())
        mismatches=[(i,m[1],m[2],ids[i][:3]) for i,m in enumerate(matches) if int(m[1])!=ids[i][1] or (normal(m[2])!=normal(ids[i][2]) and (ids[i][0],int(m[1]),m[2],ids[i][2])!=('U01',1,'ANDAMAN & NICOBAR ISLANDS','ANDAMAN & NICOBAR ISLAND'))]
        if mismatches:raise ValueError(f'Identity mismatch: {mismatches}')
        records=[]
        for index,match in enumerate(matches):
            state,pc,seat,state_name,summary_page,page=ids[index]
            record=dict(code=index+1,state_code=state,state_name=state_name,official_pc_code=pc,constituency_name=seat,name=f'{state_name} / {seat}',number_of_seats=1,detail_page=max(n for offset,n in offsets if offset<=match.start()),summary_page=summary_page,candidates=[])
            records.append(record)
            try:
                body=text[match.end():matches[index+1].start() if index+1<len(matches) else len(text)].strip()
                total=re.search(r'TOTAL:\n(\d+)\n(\d+)\n(\d+)\s*$',body)
                if not total:raise ValueError('Detailed total missing')
                rows=body[:total.start()].strip()
                candidates=record['candidates']
                for block in re.split(r'(?m)^\.\s+',rows):
                    if not block.strip():continue
                    m=re.fullmatch(r'(.+?)\n([MF])\n(.+?)\n(\d+)\n(GEN|SC|ST)\n(\d+)\n(\d+)\n(\d+)\n(\d+)\s*',block,re.S)
                    if not m:raise ValueError('Candidate row layout requires review')
                    candidates.append(dict(candidate_name=' '.join(m[1].split()),sex=m[2],party_at_election=''.join(m[3].splitlines()),age=int(m[4]),category=m[5],general_votes=int(m[6]),votes=int(m[7]),postal_votes=int(m[8]),source_row=int(m[9])))
                record.update(electors=summary_number(page,'TOTAL',450,510,280,340),votes_polled=summary_number(page,'TOTAL',450,510,370,430),valid_candidate_votes=int(total[3]))
                record['summary_totals']=dict(electors=record['electors'],votes_polled=record['votes_polled'],valid_candidate_votes=summary_number(page,'TOTAL',450,510,470,520))
                if sorted(c['source_row'] for c in candidates)!=list(range(1,len(candidates)+1)):raise ValueError('Candidate serial numbers incomplete')
                if any(c['votes']!=c['general_votes']+c['postal_votes'] for c in candidates):raise ValueError('Candidate vote components differ')
                if [sum(c[k] for c in candidates) for k in ['general_votes','postal_votes','votes']]!=[int(total[i]) for i in [1,2,3]]:raise ValueError('Candidate sums differ from detailed totals')
                if record['valid_candidate_votes']!=record['summary_totals']['valid_candidate_votes']:raise ValueError('Summary and detailed valid votes differ')
                if not 0<record['valid_candidate_votes']<=record['votes_polled']<=record['electors']:raise ValueError('Elector/voter totals inconsistent')
                ranked=sorted(candidates,key=lambda c:c['votes'],reverse=True)
                if len(ranked)<2 or ranked[0]['votes']==ranked[1]['votes']:raise ValueError('Winner requires review')
                record.update(status='validated',winner=ranked[0]['candidate_name'],margin=ranked[0]['votes']-ranked[1]['votes'])
            except ValueError as error:record.update(status='needs_review',error=str(error))
        return records


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('manifest');args=parser.parse_args()
    result=run(args.manifest,year=2004,extractor=extract)
    print(json.dumps({k:result[k] for k in ['year','validated_count','review_count']}))
