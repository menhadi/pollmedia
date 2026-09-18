"""Extract the legacy ECI percentage-column PDF layout without assuming state boundaries."""
import argparse
import hashlib
import json
import re
from datetime import datetime, timezone
from pathlib import Path

import fitz

IDENTITY = re.compile(r'^Constituency[ \t]*:?[ \t]*\n?(\d+)(?:[ \t]*\.[ \t]*|[ \t]*\n|[ \t]+)([^\n]+)\n', re.M)
TOTAL = re.compile(r'ELECTORS\s*:\s*(\d+)\s*([\d.]+)%\s*VALID VOTES\s*:?\s*(\d+)\s*VOTERS\s*:\s*(\d+)\s*POLL PERCENTAGE\s*:\s*$')
CANDIDATE = re.compile(r'(.+?)\n([MF])\n([^\n]+)\n(\d+)\n([\d.]+%|#Num!)\n(\d+)\s*', re.S)


def parse_body(body):
    issues = ['Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.']
    seats = re.match(r'NUMBER OF SEATS\s+(\d+)\s+', body)
    result = dict(number_of_seats=int(seats[1]) if seats else 1, candidates=[])
    if seats: body = body[seats.end():]
    if result['number_of_seats'] > 1:
        issues.append('Multi-member constituency; no single winner or margin is inferred.')
    old_total = re.search(r'ELECTORS\s*:\s*(\d+)\s*VOTERS\s*:\s*(\d+)\s*POLL PERCENTAGE\s*:\s*([\d.]+)%\s*VALID VOTES\s*:\s*(\d+)\s*$',body)
    if old_total:
        body=body[:old_total.start()]+f'ELECTORS :\n{old_total[1]}\n{old_total[3]}%\nVALID VOTES :\n{old_total[4]}\nVOTERS :\n{old_total[2]}\nPOLL PERCENTAGE :'
    total = TOTAL.search(body)
    rows = body[:total.start()].strip() if total else body.split('ELECTORS')[0].strip()
    rows=re.sub(r'(?m)^\.\n(\d+)\n',r'\1 . ',rows)
    lines=rows.splitlines();column_count=0
    while column_count<len(lines) and re.fullmatch(r'\d+[ \t]*\.',lines[column_count]):column_count+=1
    if column_count and len(lines)==6*column_count and all(v in ['M','F'] for v in lines[2*column_count:3*column_count]):
        rows='\n'.join(f'. {lines[column_count+i]}\n{lines[2*column_count+i]}\n{lines[3*column_count+i]}\n{lines[4*column_count+i]}\n{lines[5*column_count+i]}\n{int(lines[i].split()[0])}' for i in range(column_count))
    if total:
        result.update(electors=int(total[1]), valid_candidate_votes=int(total[3]), votes_polled=int(total[4]))
    else: issues.append('Detailed totals are missing or use an unsupported layout.')
    for block in re.split(r'(?m)(?=^\.[ \t]+|^\d+[ \t]*\.[ \t]+)', rows):
        if not block.strip(): continue
        clean=re.sub(r'^\.\s+','',block)
        clean=re.sub(r'(.+?)\n([MF])\n([^\n]+)\nUNCONTESTED\n(\d+)',r'\1\n\2\n\3\n\4\nUncontested',clean,flags=re.S)
        uncontested=re.match(r'(.+?)\n([MF])\n([^\n]+)\n(\d+)\nUncontested(?:\n|$)',clean,re.S)
        if uncontested:
            result['candidates'].append(dict(candidate_name=' '.join(uncontested[1].split()),sex=uncontested[2],party_at_election=uncontested[3],source_row=int(uncontested[4]),votes=None,general_votes=None,postal_votes=None,reported_contest_status='uncontested'))
            issues.append('The source reports an uncontested candidate without vote totals.')
            continue
        match=CANDIDATE.fullmatch(clean)
        if not match:
            leading=re.fullmatch(r'(\d+)\s*\.\s+(.+?)\n([MF])\n([^\n]+)\n(\d+)\n([\d.]+|#Num!)%?\s*',block,re.S)
            middle=re.fullmatch(r'(.+?)\n(\d+)\n([MF])\n([^\n]+)\n(\d+)\n([\d.]+)%\s*',clean,re.S)
            if leading: clean=f"{leading[2]}\n{leading[3]}\n{leading[4]}\n{leading[5]}\n{leading[6] if leading[6]=='#Num!' else leading[6]+'%'}\n{leading[1]}"
            elif middle: clean=f'{middle[1]}\n{middle[3]}\n{middle[4]}\n{middle[5]}\n{middle[6]}%\n{middle[2]}'
            match=CANDIDATE.fullmatch(clean)
        if not match:
            issues.append('Some candidate text could not be parsed; see the original PDF.')
            continue
        result['candidates'].append(dict(candidate_name=' '.join(match[1].split()), sex=match[2], party_at_election=match[3], votes=int(match[4]), reported_vote_percent=None if match[5] == '#Num!' else float(match[5][:-1]), source_row=int(match[6]), general_votes=None, postal_votes=None))
    candidates = result['candidates']
    if sorted(c['source_row'] for c in candidates) != list(range(1, len(candidates)+1)):
        issues.append('Candidate serial numbers are incomplete or duplicated.')
    if total and all(c['votes'] is not None for c in candidates):
        if sum(c['votes'] for c in candidates) != result['valid_candidate_votes']:
            issues.append('Extracted candidate votes do not match the reported valid votes.')
        if result['number_of_seats'] == 1 and not 0 < result['valid_candidate_votes'] <= result['votes_polled'] <= result['electors']:
            issues.append('Reported elector and voter totals are inconsistent.')
    result.update(status='needs_review', error='; '.join(dict.fromkeys(issues)))
    return result


def extract(path, state):
    chunks, pages, page_numbers, expected = [], {}, [], set()
    with fitz.open(path) as doc:
        for index, page in enumerate(doc):
            text = page.get_text()
            if 'DETAILED RESULTS' not in text and 'rptDetailedResults' not in text and 'ECI-REPORT-ID-VS11' not in text: continue
            footer = re.search(r'(?:rptDetailedResults - |ECI-REPORT-ID[^\n]*\s*)?Page (\d+) of\s+(\d+)\b', text)
            if not footer: continue
            # Other PDF layouts include age, addresses and postal columns. Do not reinterpret them.
            if 'VALID VOTES POLLED' in text or 'GENERAL POSTAL' in text:
                raise ValueError('Different detailed-results layout')
            page_numbers.append(int(footer[1])); expected.add(int(footer[2]))
            text = text[:footer.start()]
            lines = [line.strip() for line in text.splitlines() if line.strip() and line.strip() not in {'DETAILED RESULTS','No.','CANDIDATE','SEX','PARTY','VOTES','%'} and not line.startswith('Election Commission of India')]
            cleaned = re.sub(r'[ \t]+NUMBER OF SEATS', '\nNUMBER OF SEATS', '\n'.join(lines)) + '\n'
            cleaned=re.sub(r'Constituency[ \t]*:?[ \t]*\n(\d+)[ \t]*\.\n(\d+)[ \t]*\.[ \t]+([^\n]+)\n',r'Constituency :\n\2 . \3\n\1 . ',cleaned)
            for match in IDENTITY.finditer(cleaned): pages[int(match[1])] = index+1
            chunks.append(cleaned)
    if not expected: raise ValueError('No supported detailed report pages')
    text = '\n'.join(chunks); identities = list(IDENTITY.finditer(text))
    for i in range(len(identities)-1,0,-1):
        previous,current=identities[i-1],identities[i]
        if previous.group(1,2)==current.group(1,2) and 'ELECTORS' not in text[previous.end():current.start()]:
            text=text[:current.start()]+text[current.end():]
    identities=list(IDENTITY.finditer(text))
    codes = [int(m[1]) for m in identities]
    if not codes or len(codes) != len(set(codes)): raise ValueError('Missing or duplicated constituency identities')
    complete_pages = len(expected)==1 and page_numbers == list(range(1, max(expected)+1))
    records = []
    for i, match in enumerate(identities):
        body = text[match.end():identities[i+1].start() if i+1 < len(identities) else len(text)].strip()
        record = parse_body(body)
        record.update(code=int(match[1]), name=match[2].strip(), state_name=state, detail_page=pages[int(match[1])])
        if not complete_pages: record['error'] += '; Detailed report pages are missing, duplicated or out of order.'
        records.append(record)
    if not any(r['candidates'] for r in records): raise ValueError('No candidate rows parsed')
    return records


def select_source(manifest, source_format='pdf'):
    suffixes = ('.xls', '.xlsx') if source_format == 'workbook' else ('.pdf',)
    files = [f for f in manifest['files'] if f['file'].endswith(suffixes)]
    if len(files) == 1: return files[0]
    details = [f for f in files if re.search(r'de(?:ta|a)iled\s+resul(?:ts|sts|t)\b', f['name'], re.I)]
    if len(details) != 1: raise ValueError('No unique detailed-results source file')
    return details[0]


def run(entry, root, extractor=extract, source_format='pdf'):
    folder = root / hashlib.sha256(entry['url'].encode()).hexdigest()[:24]
    output = folder/'extraction.json'
    if output.exists(): return None
    manifest = json.loads((folder/'manifest.json').read_text(encoding='utf-8'))
    if manifest['url'] != entry['url'] or manifest['year'] != entry['year']: raise ValueError('Manifest identity differs')
    source = select_source(manifest, source_format); path = folder/source['file']
    if Path(source['file']).name != source['file'] or hashlib.sha256(path.read_bytes()).hexdigest() != source['sha256']: raise ValueError('Source integrity failed')
    records = extractor(path, entry['state'])
    data = dict(kind='ac', year=entry['year'], source_url=entry['url'], source_file=source['file'], source_sha256=source['sha256'], extracted_at=datetime.now(timezone.utc).isoformat(), records=records)
    temporary = folder/'extraction.tmp'
    temporary.write_text(json.dumps(data,indent=2,ensure_ascii=False),encoding='utf-8'); temporary.replace(output)
    return dict(state=entry['state'],year=entry['year'],tables=len(records),candidates=sum(len(r['candidates']) for r in records))


if __name__ == '__main__':
    parser=argparse.ArgumentParser(); parser.add_argument('catalogue',type=Path); parser.add_argument('root',type=Path); parser.add_argument('--year',type=int); parser.add_argument('--report',type=Path); parser.add_argument('--layout',choices=['legacy','components','symbols','flat','dot'],default='legacy'); args=parser.parse_args()
    extractor = extract
    if args.layout == 'components':
        from extract_assembly_components import extract as extractor
    if args.layout == 'symbols':
        from extract_assembly_symbols import extract as extractor
    if args.layout == 'flat':
        from extract_assembly_flat import extract as extractor
    if args.layout == 'dot':
        from extract_assembly_2000s import extract as extractor
    results=[]; remaining=[]
    for entry in json.loads(args.catalogue.read_text(encoding='utf-8'))['entries']:
        if args.year is not None and entry['year'] != args.year: continue
        try:
            result=run(entry,args.root,extractor,'workbook' if args.layout == 'flat' else 'pdf')
            if result: results.append(result); print(json.dumps(result),flush=True)
        except Exception as error:
            remaining.append(dict(state=entry['state'],year=entry['year'],source_url=entry['url'],reason=str(error)))
    report = dict(extracted=results,remaining=remaining)
    if args.report:
        with args.report.open('x',encoding='utf-8') as target: json.dump(report,target,indent=2)
    print(json.dumps(report),flush=True)
