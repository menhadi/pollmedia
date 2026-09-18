"""ECI PDF layouts with general/postal votes, before symbol and NOTA columns."""
import re
import fitz

HEADERS = {'CANDIDATE NAME','SEX','AGE CATEGORY','AGE  CATEGORY','PARTY','POSTAL','TOTAL','GENERAL','VALID VOTES POLLED','DETAILED RESULTS','% VOTES','POLLED'}
IDENTITIES = [re.compile(r'TOTAL ELECTORS :\n(\d+)\.\n(\d+)\n([^\n]+)\nConstituency\n'), re.compile(r'([^\n]+)\n(\d+)\.\nConstituency\nTOTAL ELECTORS :\n(\d+)\n')]
ROW = re.compile(r'([^\n]+(?:\n[^\d\n][^\n]*)*?)\n([MFO])\n(\d+)\n(GEN|SC|ST)\n([^\n]+)\n(\d+)\n(\d+)\n(\d+)\n([\d.]+)\n(\d+)(?:\n|$)')
TOTAL = re.compile(r'(\d+)\n(\d+)\nTOTAL:\n(\d+)(?:\nTURNOUT\n([\d.]+))?\s*$')


def parse_body(body):
    issues = ['Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.']
    body = re.sub(r'\n\d+\n\d+\nGRAND TOTAL:\n\d+\s*$', '', body)
    total = TOTAL.search(body)
    rows = body[:total.start()] if total else body
    matches = list(ROW.finditer(rows))
    if ''.join(m[0] for m in matches).strip() != rows.strip(): issues.append('Some candidate text could not be parsed; see the original PDF.')
    candidates = [dict(candidate_name=' '.join(m[1].split()),sex=m[2],age=int(m[3]),category=m[4],party_at_election=m[5],postal_votes=int(m[6]),votes=int(m[7]),general_votes=int(m[8]),reported_vote_percent=float(m[9]),source_row=int(m[10])) for m in matches]
    result = dict(candidates=candidates,number_of_seats=1)
    if not total: issues.append('Detailed totals are missing or use an unsupported layout.')
    else:
        result['detail_totals'] = dict(postal_votes=int(total[1]),votes=int(total[2]),general_votes=int(total[3]))
        result['valid_candidate_votes'] = int(total[2])
        if any(sum(c[k] for c in candidates) != v for k,v in result['detail_totals'].items()): issues.append('Candidate sums do not match detailed report totals.')
    if any(c['general_votes']+c['postal_votes'] != c['votes'] for c in candidates): issues.append('Candidate vote components do not match total votes.')
    if sorted(c['source_row'] for c in candidates) != list(range(1,len(candidates)+1)): issues.append('Candidate serial numbers are incomplete or duplicated.')
    result.update(status='needs_review',error='; '.join(issues))
    return result


def extract(path, state):
    chunks=[]; locations=[]
    with fitz.open(path) as doc:
        for index,page in enumerate(doc):
            text=page.get_text()
            if 'DETAILED RESULTS' not in text: continue
            if 'SYMBOL' in text or 'NOTA' in text: raise ValueError('Symbol/NOTA layout requires a different adapter')
            if 'VALID VOTES POLLED' not in text: raise ValueError('Unsupported column layout')
            lines=[line.strip() for line in text.splitlines() if line.strip() and line.strip() not in HEADERS and not line.startswith('Election Commission of India') and not re.fullmatch(r'Page \d+ of \d+',line.strip())]
            cleaned='\n'.join(lines)+'\n'; locations.append((sum(len(c) for c in chunks), index+1));chunks.append(cleaned)
    text=''.join(chunks)
    layouts=[(i,list(pattern.finditer(text))) for i,pattern in enumerate(IDENTITIES)]
    layouts=[item for item in layouts if item[1]]
    if len(layouts)!=1: raise ValueError('No unambiguous supported constituency layout')
    layout,identities=layouts[0];records=[];codes=set()
    for i,match in enumerate(identities):
        code,electors,name=(int(match[1]),int(match[2]),match[3]) if layout==0 else (int(match[2]),int(match[3]),match[1])
        if code in codes: raise ValueError('Duplicate constituency identity')
        codes.add(code)
        body=text[match.end():identities[i+1].start() if i+1<len(identities) else len(text)].strip()
        record=parse_body(body)
        record.update(code=code,name=name,state_name=state,electors=electors,detail_page=max(page for start,page in locations if start<=match.start()))
        if record.get('valid_candidate_votes',0)>electors: record['error']+='; Reported valid votes exceed electors.'
        records.append(record)
    if not any(r['candidates'] for r in records): raise ValueError('No candidate rows parsed')
    return records
