"""2009 Lok Sabha national archive; state-scoped official identities."""
import argparse
import hashlib
import json
import re
from pathlib import Path
import fitz
from extract_state_election_2012 import number


def extract(detail, summary):
    with fitz.open(summary) as summaries, fitz.open(detail) as detailed:
        identities = []
        for i, page in enumerate(summaries):
            match = re.search(r'State/UT\s*:\s*(\w+).*?Constituency\s*:\s*([^\n]+).*?No\.\s*:\s*(\d+)', page.get_text(), re.S)
            if match:
                identities.append((match[1], int(match[3]), match[2].strip(), i + 1, page))
        if len(identities) != 543 or len({(s,c) for s,c,n,i,p in identities}) != 543:
            raise ValueError('Expected 543 unique state/PC summaries')
        headers = {'SL NO','CANDIDATE NAME','SEX','AGE CATEGORY','PARTY','POSTAL TOTAL','GENERAL','Votes Secured','% of votes secured','Over total','elctors in','constituency','votes polled in','PART-II','25 - CONSTITUENCY WISE DETAILED RESULTS','GENERAL ELECTIONS - INDIA, 2009'}
        parts, offsets = [], []
        length = 0
        for i, page in enumerate(detailed):
            cleaned = '\n'.join(x.strip() for x in page.get_text().splitlines() if x.strip() and x.strip() not in headers and not x.startswith('Election Commission of India'))
            offsets.append((length,i+1));parts.append(cleaned);length += len(cleaned)+1
        text = '\n'.join(parts)
        pattern = re.compile(r'(?m)^(\d+)\n([^\n]+)\nCONSTITUENCY :\n\.\n(\d+)\n\( Total Electors\n\)\n')
        matches = list(pattern.finditer(text))
        if len(matches) != 543:
            raise ValueError(f'Expected 543 detailed identities, found {len(matches)}')
        records=[]
        normal=lambda value: re.sub(r'[^a-z0-9]','',value.lower())
        name_variants = {('S20',12,'TONK-SAWAI MADHOPU','TONK-SAWAI MADHOPUR'),('S28',4,'Nainital-udhamsingh Nag','Nainital-udhamsingh Nagar'),('U01',1,'Andaman & nicobar islan','Andaman & nicobar islands')}
        for index, match in enumerate(matches):
            state, pc_code, seat, summary_page, page = identities[index]
            if int(match[1]) != pc_code or (normal(match[2]) != normal(seat) and (state,pc_code,match[2],seat) not in name_variants):
                raise ValueError('State-scoped source order/constituency identity differs')
            record=dict(code=index+1, official_pc_code=pc_code, state_code=state, name=f'{state} / {seat}', constituency_name=seat, detailed_name=match[2], number_of_seats=1, detail_page=max(n for offset,n in offsets if offset<=match.start()), summary_page=summary_page, electors=int(match[3]), candidates=[])
            records.append(record)
            try:
                body=text[match.end():matches[index+1].start() if index+1<len(matches) else len(text)]
                total=re.search(r'(?m)^(\d+)\n(\d+)\nTOTAL:\n(\d+)\n',body)
                if not total:raise ValueError('Detailed total missing')
                rows=body[:total.start()].strip()
                row_pattern=re.compile(r'(\d+)\n(.+?)\n([MF])\n(\d+)\n(GEN|SC|ST)\n(.+?)\n(\d+)\n(\d+)\n(\d+)\n([\d.]+)\n([\d.]+)(?:\n|$)',re.S)
                candidates=list(row_pattern.finditer(rows))
                if ''.join(m[0] for m in candidates).strip()!=rows:raise ValueError('Candidate layout was not fully parsed')
                record['candidates']=[dict(source_row=int(m[1]),candidate_name=' '.join(m[2].split()),sex=m[3],age=int(m[4]),category=m[5],party_at_election=''.join(m[6].splitlines()),postal_votes=int(m[7]),votes=int(m[8]),general_votes=int(m[9])) for m in candidates]
                cs=record['candidates']
                if sorted(c['source_row'] for c in cs)!=list(range(1,len(cs)+1)):raise ValueError('Candidate sequence incomplete')
                if any(c['votes']!=c['general_votes']+c['postal_votes'] for c in cs):raise ValueError('Candidate components differ')
                if [sum(c[k] for c in cs) for k in ['postal_votes','votes','general_votes']] != [int(total[x]) for x in [1,2,3]]:raise ValueError('Candidate sums differ from detailed totals')
                record.update(valid_candidate_votes=int(total[2]),votes_polled=number(page,'4. TOTAL'))
                record['summary_totals']=dict(electors=number(page,'3. TOTAL'),votes_polled=record['votes_polled'],valid_candidate_votes=number(page,'3. TOTAL VALID VOTES POLLED'))
                if record['electors']!=record['summary_totals']['electors'] or record['valid_candidate_votes']!=record['summary_totals']['valid_candidate_votes']:raise ValueError('Summary and detailed totals differ')
                if not 0<record['valid_candidate_votes']<=record['votes_polled']<=record['electors']:raise ValueError('Elector and voter totals inconsistent')
                ranked=sorted(cs,key=lambda c:c['votes'],reverse=True)
                if len(ranked)<2 or ranked[0]['votes']==ranked[1]['votes']:raise ValueError('Winner requires review')
                record.update(status='validated',winner=ranked[0]['candidate_name'],margin=ranked[0]['votes']-ranked[1]['votes'])
            except ValueError as error:
                record.update(status='needs_review',error=str(error))
        return records


def run(manifest_path, year=2009, extractor=extract):
    manifest_path=Path(manifest_path)
    manifest=json.loads(manifest_path.read_text(encoding='utf-8'))
    if manifest['kind']!='pc' or manifest['year']!=year:raise ValueError(f'Expected {year} PC archive')
    files=[]
    names = ['Constituency Wise Detailed Result.pdf','Constituency Data Summary.pdf'] if year == 2009 else ['2004 (Vol I).pdf','2004 (Vol II).pdf']
    for name in names:
        matches=[f for f in manifest['files'] if f['name']==name]
        if len(matches)!=1:raise ValueError('Missing or ambiguous source report')
        source=matches[0]
        if Path(source['file']).name!=source['file']:raise ValueError('Invalid source filename')
        path=manifest_path.parent/source['file']
        if hashlib.sha256(path.read_bytes()).hexdigest()!=source['sha256']:raise ValueError('Source checksum differs')
        files.append(source)
    records=extractor(*(manifest_path.parent/f['file'] for f in files))
    result=dict(adapter=f'eci-pc-{year}-v1',kind='pc',year=year,source_url=manifest['url'],source_file=files[0]['file'],source_sha256=files[0]['sha256'],additional_sources=[files[1]],records=records,validated_count=sum(r['status']=='validated' for r in records),review_count=sum(r['status']!='validated' for r in records),coverage_note=f'National {year} Lok Sabha edition. Each official PC code is scoped to its source State/UT code. Review record numbers are internal identifiers, not official constituency codes. Historical mapping and publication are pending.')
    destination=manifest_path.parent/'extraction.json';temporary=destination.with_suffix('.tmp');temporary.write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8');temporary.replace(destination)
    return result


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('manifest');args=parser.parse_args();result=run(args.manifest)
    print(json.dumps({k:result[k] for k in ['year','validated_count','review_count']}))
