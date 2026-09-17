"""Strict 2012 UP PDF adapter: summary cells plus detailed candidate rows."""
import json
import re
import sys
from pathlib import Path
import fitz
from extract_election_import import candidate


def number(page, label):
    matches = [m for m in page.search_for(label) if label != '3. TOTAL' or m.y0 < 300]
    if len(matches) != 1:
        raise ValueError('Summary label missing or ambiguous: ' + label)
    y = matches[0].y0
    values = [w[4] for w in page.get_text('words') if w[0] > 500 and abs(w[1] - y) < 2 and re.fullmatch(r'\d+',w[4])]
    if len(values) != 1:
        raise ValueError('Summary numeric cell missing or ambiguous: ' + label)
    return int(values[0])


def extract_state(path):
    doc = fitz.open(path)
    summary = {}
    detail = []
    detail_pages = {}
    headers = {'CANDIDATE NAME','SEX','AGE  CATEGORY','PARTY','POSTAL','TOTAL','GENERAL','VALID VOTES POLLED','DETAILED RESULTS','% VOTES','POLLED'}
    for index,page in enumerate(doc):
        text = page.get_text()
        if 'CONSTITUENCY DATA - SUMMARY' in text:
            match = re.search(r'SUMMARY\s+(\d+)\s+CONSTITUENCY\s*:\s*-\s*([^\n]+)',text)
            if not match or int(match[1]) in summary:
                raise ValueError('Summary constituency identity changed.')
            summary[int(match[1])] = (index+1,match[2].strip(),page)
        if 'DETAILED RESULTS' in text:
            lines = [line.strip() for line in text.splitlines() if line.strip() and line.strip() not in headers and not line.startswith('Election Commission of India') and not re.fullmatch(r'Page \d+ of \d+',line.strip())]
            cleaned='\n'.join(lines)
            for match in re.finditer(r'\n(\d+)\.\nConstituency',cleaned):
                detail_pages[int(match[1])]=index+1
            detail.append(cleaned)
    text = '\n'.join(detail)
    pattern = re.compile(r'([^\n]+)\n(\d+)\.\nConstituency\nTOTAL ELECTORS :\n(\d+)\n')
    identities = list(pattern.finditer(text))
    if [int(m[2]) for m in identities] != list(range(1,404)) or sorted(summary) != list(range(1,404)):
        raise ValueError('Expected complete ordered 403-seat summary and detail coverage.')
    results=[]
    row_pattern = re.compile(r'(.+?)\n([MFO])\n(\d+)\n(GEN|SC|ST)\n([^\n]+)\n(\d+)\n(\d+)\n(\d+)\n([\d.]+)\n(\d+)(?:\n|$)',re.S)
    for i,match in enumerate(identities):
        code,seat,electors = int(match[2]),match[1],int(match[3])
        record=dict(code=code,name=seat)
        try:
            page_no,summary_name,page=summary[code]
            normal=lambda s: re.sub(r'[^a-z0-9]','',s.lower())
            if normal(seat)!=normal(summary_name):
                raise ValueError('Summary and detailed seat names differ.')
            body=text[match.end():identities[i+1].start() if i+1<len(identities) else len(text)].strip()
            body=re.sub(r'\n\d+\n\d+\nGRAND TOTAL:\n\d+\s*$','',body)
            total=re.search(r'(\d+)\n(\d+)\nTOTAL:\n(\d+)\nTURNOUT\n([\d.]+)\s*$',body)
            if not total:
                raise ValueError('Detailed total row changed.')
            rows=body[:total.start()]
            matches=list(row_pattern.finditer(rows))
            if ''.join(m[0] for m in matches).strip()!=rows.strip():
                raise ValueError('Candidate layout could not be fully parsed.')
            candidates=[candidate(m[10], ' '.join(m[1].split()),m[5],m[8],m[6],m[7]) for m in matches]
            if [c['source_row'] for c in candidates]!=list(range(1,len(candidates)+1)):
                raise ValueError('Candidate ranks are incomplete.')
            if electors!=number(page,'3. TOTAL'):
                raise ValueError('Elector counts differ between summary and detail.')
            valid,polled=number(page,'3. TOTAL VALID VOTES POLLED'),number(page,'4. TOTAL')
            if sum(c['votes'] for c in candidates)!=valid or valid!=int(total[2]) or sum(c['postal_votes'] for c in candidates)!=int(total[1]) or sum(c['general_votes'] for c in candidates)!=int(total[3]):
                raise ValueError('Candidate totals do not reconcile with report totals.')
            record['payload']=dict(year=2012,code=code,name=seat,candidates=candidates,electors=electors,votes_polled=polled,valid_candidate_votes=valid,source_locator=f'2012 statistical report; summary PDF page {page_no}; detailed constituency {code}, PDF pages {detail_pages[code]}-{detail_pages.get(code+1,len(doc))}. No NOTA option in this edition.')
        except (ValueError,IndexError) as error:
            record['error']=str(error)
        results.append(record)
    doc.close()
    return results


if __name__ == '__main__':
    try:
        sys.stdout.buffer.write(json.dumps(extract_state(Path(sys.argv[1])),ensure_ascii=False).encode('utf-8'))
    except Exception as error:
        sys.stdout.buffer.write(json.dumps({'error':str(error)}).encode('utf-8'))
        sys.exit(1)
