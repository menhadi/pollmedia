"""Read ECI symbol-column PDF tables using the printed column positions."""
import re
from statistics import median
import fitz


def integer(value):
    return int(value) if re.fullmatch(r'\d+',value) else None


def cells(words, boundaries):
    values=[]
    for left,right in zip(boundaries,boundaries[1:]):
        selected=sorted([w for w in words if left <= (w[0]+w[2])/2 < right],key=lambda w:(round(w[1]/2)*2,w[0]))
        values.append(' '.join(w[4] for w in selected))
    return values


def candidate(values):
    serial,name,sex,age,category,party,symbol,general,postal,total,percent=values
    return dict(source_row=integer(serial),candidate_name=name,sex=sex or None,age=integer(age),category=category or None,party_at_election=party,election_symbol=symbol,general_votes=integer(general),postal_votes=integer(postal),votes=integer(total),reported_vote_percent=float(percent) if re.fullmatch(r'\d+(?:\.\d+)?',percent) else None,is_nota=name.lower()=='none of the above' and party=='NOTA',source_values=values)


def finish(record):
    issues=['Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.']+record.pop('issues')
    cs=record['candidates'];totals=record.get('detail_totals')
    if record['electors'] is None: issues.append('Elector total is missing or unreadable in the source heading.')
    if not cs: issues.append('No candidate rows were parsed.')
    if [c['source_row'] for c in cs]!=list(range(1,len(cs)+1)): issues.append('Candidate serial numbers are incomplete or duplicated.')
    if any(not c['candidate_name'] or not c['party_at_election'] or None in [c['general_votes'],c['postal_votes'],c['votes']] for c in cs): issues.append('One or more candidate cells are missing or unreadable; original cells are retained.')
    else:
        if any(c['general_votes']+c['postal_votes']!=c['votes'] for c in cs): issues.append('Candidate vote components do not match totals.')
        if totals and all(totals[k] is not None for k in ['general_votes','postal_votes','votes']):
            if any(sum(c[k] for c in cs)!=totals[k] for k in totals): issues.append('Candidate and NOTA sums differ from detailed totals.')
            else: record['valid_candidate_votes']=sum(c['votes'] for c in cs if not c['is_nota'])
        if record['electors'] is not None and sum(c['votes'] for c in cs)>record['electors']: issues.append('Candidate and NOTA votes exceed electors.')
    if totals is None or any(v is None for v in totals.values()): issues.append('Detailed totals are missing or unreadable.')
    record.update(status='needs_review',error='; '.join(dict.fromkeys(issues)))
    return record


def extract(path,state,document=None):
    records=[];current=None;codes=set();previous_headers=None;ocr_layout_pages=[]
    tolerance=6 if getattr(document,'is_ocr',False) else 2
    with (document if document is not None else fitz.open(path)) as doc:
        for page_index,page in enumerate(doc):
            text=page.get_text()
            if 'DETAILED RESULTS' not in text: continue
            words=page.get_text('words')
            symbols=[w for w in words if w[4]=='SYMBOL' and w[1]<150]
            if len(symbols)!=1: raise ValueError('No unique symbol column')
            header_y=symbols[0][1]
            headers={label:[w for w in words if w[4]==label and abs(w[1]-header_y)<tolerance] for label in ['CANDIDATE','SEX','AGE','CATEGORY','PARTY','SYMBOL','GENERAL','POSTAL','TOTAL','POLLED']}
            if hasattr(page,'search_for'):
                for label in headers:
                    if len(headers[label])!=1:
                        headers[label]=[(r.x0,r.y0,r.x1,r.y1,label) for r in page.search_for(label) if abs(r.y0-header_y)<tolerance]
            if getattr(document,'is_ocr',False) and previous_headers and any(len(v)!=1 for v in headers.values()):
                shared=[k for k,v in headers.items() if len(v)==1]
                if len(shared)>=6:
                    dx=median(headers[k][0][0]-previous_headers[k][0][0] for k in shared)
                    dy=median(headers[k][0][1]-previous_headers[k][0][1] for k in shared)
                    for k,v in headers.items():
                        if len(v)!=1:
                            old=previous_headers[k][0];headers[k]=[(old[0]+dx,old[1]+dy,old[2]+dx,old[3]+dy,k)]
                    ocr_layout_pages.append(page_index+1)
            omitted=[]
            if not headers['SEX'] and not headers['GENERAL'] and len(headers['AGE'])==len(headers['POSTAL'])==1:
                omitted=['sex','general_votes']
                headers['SEX']=[(headers['AGE'][0][0]-33,header_y,0,0,'SEX')]
                headers['GENERAL']=[(headers['POSTAL'][0][0]-60,header_y,0,0,'GENERAL')]

            if any(len(items)!=1 for items in headers.values()): raise ValueError('Unsupported symbol table headers on page '+str(page_index+1)+': '+','.join(k for k,v in headers.items() if len(v)!=1))
            previous_headers=headers
            boundaries=[0,headers['CANDIDATE'][0][0]-14]+[headers[k][0][0]-6 for k in ['SEX','AGE','CATEGORY','PARTY','SYMBOL','GENERAL','POSTAL','TOTAL','POLLED']]+[page.rect.width]
            if boundaries!=sorted(boundaries): raise ValueError('Unexpected column order')
            footer_y=min([w[1] for w in words if w[4]=='Page' and w[1]>page.rect.height*0.85],default=page.rect.height)
            body=[w for w in words if w[1]>header_y+12 and w[1]<footer_y-2]
            events=[]
            for w in body:
                if w[4]=='Constituency': events.append((w[1],'identity',w))
                elif w[4]=='TOTAL:':
                    kind='grand_total' if any(other[4]=='GRAND' and abs(other[1]-w[1])<tolerance for other in body) else 'total'
                    events.append((w[1],kind,w))
                elif re.fullmatch(r'\d+',w[4]) and w[0]<boundaries[1] and w[2]<boundaries[1]+1: events.append((w[1],'candidate',w))
            if getattr(document,'is_ocr',False):
                for w in body:
                    if not boundaries[1]<=w[0]<boundaries[4]:continue
                    if any(abs(event[0]-w[1])<tolerance for event in events):continue
                    votes=[v for v in body if v[0]>=boundaries[7] and abs(v[1]-w[1])<tolerance and re.fullmatch(r'\d+',v[4])]
                    gender=re.fullmatch(r'[MFO]\d*',w[4]) and boundaries[2]<=w[0]<boundaries[4]
                    if gender or len(votes)>=2:events.append((w[1],'candidate',w))
            events.sort(key=lambda e:e[0])
            for i,(y,kind,anchor) in enumerate(events):
                end=events[i+1][0]-tolerance if i+1<len(events) else footer_y-2
                band=[w for w in body if y-tolerance<=w[1]<end]
                if kind=='grand_total': continue
                if kind=='identity':
                    line=' '.join(w[4] for w in sorted([w for w in band if abs(w[1]-y)<tolerance],key=lambda w:w[0]))
                    match=re.fullmatch(r'Constituency\s+(\d+)[.,]\s*(.+?)\s+TOTAL ELECTORS\s*:?\s*(.*)',line)
                    if not match: raise ValueError('Unsupported constituency heading on PDF page '+str(page_index+1)+': '+line)
                    code=int(match[1])
                    if code in codes:
                        current=next(r for r in records if r['code']==code)
                        if current['name']!=match[2]: raise ValueError('Conflicting repeated constituency name')
                        current['issues'].append('Multiple source table fragments use this constituency code; candidate rows are preserved as printed.')
                        continue
                    codes.add(code)
                    current=dict(code=code,name=match[2],state_name=state,source_heading=line,electors=integer(match[3] or ''),number_of_seats=1,detail_page=page_index+1,candidates=[],issues=[])
                    records.append(current)
                elif current is None: raise ValueError('Candidate rows precede constituency identity')
                elif kind=='candidate':
                    values=cells(band,boundaries);row=candidate(values);row['source_page']=page_index+1
                    for field in omitted: row[field]=None
                    if omitted: current['issues'].append('The printed detailed table omits sex and general-vote columns; no values were inferred.')
                    current['candidates'].append(row)
                else:
                    values=cells([w for w in band if abs(w[1]-y)<tolerance],boundaries)
                    if 'detail_totals' in current:
                        current['issues'].append('Multiple detailed total rows were found.')
                        current.setdefault('source_total_rows',[]).append(current['detail_totals'])
                    current['detail_totals']=dict(general_votes=integer(values[7]),postal_votes=integer(values[8]),votes=integer(values[9]))
    if not records or not any(r['candidates'] for r in records): raise ValueError('No symbol-table candidate rows parsed')
    if ocr_layout_pages:
        for record in records:record['issues'].append('OCR column headings required alignment from adjacent report pages: '+', '.join(map(str,ocr_layout_pages))+'.')
    return [finish(r) for r in records]
