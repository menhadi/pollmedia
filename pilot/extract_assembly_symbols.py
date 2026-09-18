"""Read ECI symbol-column PDF tables using the printed column positions."""
import re
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
    if not cs: issues.append('No candidate rows were parsed.')
    if [c['source_row'] for c in cs]!=list(range(1,len(cs)+1)): issues.append('Candidate serial numbers are incomplete or duplicated.')
    if any(not c['candidate_name'] or not c['party_at_election'] or None in [c['general_votes'],c['postal_votes'],c['votes']] for c in cs): issues.append('One or more candidate cells are missing or unreadable; original cells are retained.')
    else:
        if any(c['general_votes']+c['postal_votes']!=c['votes'] for c in cs): issues.append('Candidate vote components do not match totals.')
        if totals and all(totals[k] is not None for k in ['general_votes','postal_votes','votes']):
            if any(sum(c[k] for c in cs)!=totals[k] for k in totals): issues.append('Candidate and NOTA sums differ from detailed totals.')
            else: record['valid_candidate_votes']=sum(c['votes'] for c in cs if not c['is_nota'])
        if sum(c['votes'] for c in cs)>record['electors']: issues.append('Candidate and NOTA votes exceed electors.')
    if totals is None or any(v is None for v in totals.values()): issues.append('Detailed totals are missing or unreadable.')
    record.update(status='needs_review',error='; '.join(dict.fromkeys(issues)))
    return record


def extract(path,state):
    records=[];current=None;codes=set()
    with fitz.open(path) as doc:
        for page_index,page in enumerate(doc):
            text=page.get_text()
            if 'DETAILED RESULTS' not in text: continue
            words=page.get_text('words')
            symbols=[w for w in words if w[4]=='SYMBOL' and w[1]<150]
            if len(symbols)!=1: raise ValueError('No unique symbol column')
            header_y=symbols[0][1]
            headers={label:[w for w in words if w[4]==label and abs(w[1]-header_y)<2] for label in ['CANDIDATE','SEX','AGE','CATEGORY','PARTY','SYMBOL','GENERAL','POSTAL','TOTAL','POLLED']}
            if any(len(items)!=1 for items in headers.values()): raise ValueError('Unsupported symbol table headers')
            boundaries=[0,headers['CANDIDATE'][0][0]-14]+[headers[k][0][0]-6 for k in ['SEX','AGE','CATEGORY','PARTY','SYMBOL','GENERAL','POSTAL','TOTAL','POLLED']]+[page.rect.width]
            if boundaries!=sorted(boundaries): raise ValueError('Unexpected column order')
            footer_y=min([w[1] for w in words if w[4]=='Page' and w[1]>page.rect.height*0.85],default=page.rect.height)
            body=[w for w in words if w[1]>header_y+12 and w[1]<footer_y-2]
            events=[]
            for w in body:
                if w[4]=='Constituency': events.append((w[1],'identity',w))
                elif w[4]=='TOTAL:':
                    kind='grand_total' if any(other[4]=='GRAND' and abs(other[1]-w[1])<2 for other in body) else 'total'
                    events.append((w[1],kind,w))
                elif re.fullmatch(r'\d+',w[4]) and w[0]<boundaries[1] and w[2]<boundaries[1]+1: events.append((w[1],'candidate',w))
            events.sort(key=lambda e:e[0])
            for i,(y,kind,anchor) in enumerate(events):
                end=events[i+1][0]-2 if i+1<len(events) else footer_y-2
                band=[w for w in body if y-2<=w[1]<end]
                if kind=='grand_total': continue
                if kind=='identity':
                    line=' '.join(w[4] for w in sorted([w for w in band if abs(w[1]-y)<2],key=lambda w:w[0]))
                    match=re.fullmatch(r'Constituency\s+(\d+)\.\s*(.+?)\s+TOTAL ELECTORS\s*:\s*(\d+)',line)
                    if not match: raise ValueError('Unsupported constituency heading: '+line)
                    code=int(match[1])
                    if code in codes: raise ValueError('Repeated constituency identity')
                    codes.add(code)
                    current=dict(code=code,name=match[2],state_name=state,electors=int(match[3]),number_of_seats=1,detail_page=page_index+1,candidates=[],issues=[])
                    records.append(current)
                elif current is None: raise ValueError('Candidate rows precede constituency identity')
                elif kind=='candidate':
                    values=cells(band,boundaries);row=candidate(values);row['source_page']=page_index+1
                    current['candidates'].append(row)
                else:
                    values=cells([w for w in band if abs(w[1]-y)<2],boundaries)
                    if 'detail_totals' in current: current['issues'].append('Multiple detailed total rows were found.')
                    current['detail_totals']=dict(general_votes=integer(values[7]),postal_votes=integer(values[8]),votes=integer(values[9]))
    if not records or not any(r['candidates'] for r in records): raise ValueError('No symbol-table candidate rows parsed')
    return [finish(r) for r in records]
