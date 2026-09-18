"""Extract ECI 2000s dot-prefixed candidate tables without current-boundary assumptions."""
import re
import fitz

IDENTITY=re.compile(r'^(?:Constituency[ \t:.]*\n?[.\n ]*)?(\d+)[ \t]*\.[ \t]+([^\n]+)\n',re.M)
HEADERS={'No.','No. CANDIDATE NAME','CANDIDATE','SEX','VALID VOTES POLLED','TOTAL','AGE','CATEGORY','CATE-','GORY','PARTY','GENERAL','POSTAL','GENERAL POSTAL','DETAILED RESULTS','AGE CATEGORY','HINDI NAME','ADDRESS'}


def parse(body):
    issues=['Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.']
    total=re.search(r'TOTAL:\s*(\d+)\s+(\d+)\s+(\d+)',body)
    rows=body[:total.start()] if total else body.split('TOTAL:')[0]
    candidates=[]
    for block in re.split(r'(?m)^\.[ \t]+',rows):
        if not block.strip():continue
        # Report generations place the candidate serial in one of two positions.
        a=re.match(r'(.+?)\n([MFO])\n(\d+)\n(\d+)\n([^\n]+)\n(\d+)\n(GEN|SC|ST|BL)\n(\d+)\n(\d+)(?:\n|$)',block,re.S)
        b=re.match(r'(.+?)\n([MFO])\n(\d+)\n(\d+)\n(\d+)\n([^\n]+)\n(\d+)\n(GEN|SC|ST|BL)\n(\d+)(?:\n|$)',block,re.S)
        blank=re.match(r'(.+?)\n([MFO])\n(\d+)\n(?:Uncontested\n)?([^\n]+)\n(\d+)\n(GEN|SC|ST|BL)(?:\n|$)',block,re.S)
        if not a and not b and blank:
            candidates.append(dict(candidate_name=' '.join(blank[1].split()),sex=blank[2],source_row=int(blank[3]),party_at_election=blank[4],age=int(blank[5]),category=blank[6],votes=None,general_votes=None,postal_votes=None,reported_contest_status='uncontested' if 'Uncontested' in block else 'votes_not_reported'))
            issues.append('Candidate identity is reported without vote totals; votes remain blank.')
            continue
        if a:
            name,sex,general,postal,party,age,category,votes,serial=a.groups()
        elif b:
            name,sex,general,postal,serial,party,age,category,votes=b.groups()
        else:
            issues.append('Some candidate text could not be parsed; see the original PDF.');continue
        candidates.append(dict(candidate_name=' '.join(name.split()),sex=sex,general_votes=int(general),postal_votes=int(postal),party_at_election=party,age=int(age),category=category,votes=int(votes),source_row=int(serial)))
    record=dict(candidates=candidates,number_of_seats=1)
    if total:
        totals=dict(general_votes=int(total[1]),postal_votes=int(total[2]),votes=int(total[3]));record['detail_totals']=totals;record['valid_candidate_votes']=totals['votes']
        if any(c['votes'] is None for c in candidates) or any(sum(c[k] for c in candidates)!=v for k,v in totals.items()):issues.append('Candidate sums differ from detailed totals.')
    else:issues.append('Detailed total row missing or unreadable.')
    if any(c['general_votes']+c['postal_votes']!=c['votes'] for c in candidates if c['votes'] is not None):issues.append('Candidate vote components differ from total.')
    if sorted(c['source_row'] for c in candidates)!=list(range(1,len(candidates)+1)):issues.append('Candidate serial numbers incomplete or duplicated.')
    record.update(status='needs_review',error='; '.join(dict.fromkeys(issues)))
    return record


def extract(path,state):
    chunks=[];locations=[]
    with fitz.open(path) as doc:
        for index,page in enumerate(doc):
            text=page.get_text()
            if 'DETAILED RESULTS' not in text:continue
            if 'VALID VOTES POLLED' not in text or 'SYMBOL' in text:raise ValueError('Different detailed table format')
            text=re.split(r'rptDetailedResults - Page|Page \d+ of \d+',text)[0]
            lines=[line.strip() for line in text.splitlines() if line.strip() and line.strip() not in HEADERS and not line.startswith('Election Commission of India')]
            chunk='\n'.join(lines)+'\n';locations.append((sum(len(c) for c in chunks),index+1));chunks.append(chunk)
    text=''.join(chunks);identities=list(IDENTITY.finditer(text));records=[];seen=set()
    for i,m in enumerate(identities):
        code=int(m[1])
        if code in seen:raise ValueError('Repeated constituency code')
        seen.add(code);body=text[m.end():identities[i+1].start() if i+1<len(identities) else len(text)]
        record=parse(body);record.update(code=code,name=m[2],state_name=state,detail_page=max(page for start,page in locations if start<=m.start()));records.append(record)
    if not records or not any(r['candidates'] for r in records):raise ValueError('No supported dot-prefixed candidate rows')
    return records
