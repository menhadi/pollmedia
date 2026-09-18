"""Extract the legacy ECI percentage-column PDF layout without assuming state boundaries."""
import argparse
import hashlib
import json
import re
from datetime import datetime, timezone
from pathlib import Path

import fitz

IDENTITY = re.compile(r'^Constituency[ \t]*:?[ \t]*\n?(\d+)(?:[ \t]*\.[ \t]*|[ \t]*\n)([^\n]+)\n', re.M)
TOTAL = re.compile(r'ELECTORS\s*:\s*(\d+)\s*([\d.]+)%\s*VALID VOTES\s*:?\s*(\d+)\s*VOTERS\s*:\s*(\d+)\s*POLL PERCENTAGE\s*:\s*$')
CANDIDATE = re.compile(r'(.+?)\n([MF])\n([^\n]+)\n(\d+)\n([\d.]+%|#Num!)\n(\d+)\s*', re.S)


def parse_body(body):
    issues = ['Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.']
    seats = re.match(r'NUMBER OF SEATS\s+(\d+)\s+', body)
    result = dict(number_of_seats=int(seats[1]) if seats else 1, candidates=[])
    if seats: body = body[seats.end():]
    if result['number_of_seats'] > 1:
        issues.append('Multi-member constituency; no single winner or margin is inferred.')
    total = TOTAL.search(body)
    rows = body[:total.start()].strip() if total else body
    if total:
        result.update(electors=int(total[1]), valid_candidate_votes=int(total[3]), votes_polled=int(total[4]))
    else: issues.append('Detailed totals are missing or use an unsupported layout.')
    for block in re.split(r'(?m)^\.\s+', rows):
        if not block.strip(): continue
        match = CANDIDATE.fullmatch(block)
        if not match:
            issues.append('Some candidate text could not be parsed; see the original PDF.')
            continue
        result['candidates'].append(dict(candidate_name=' '.join(match[1].split()), sex=match[2], party_at_election=match[3], votes=int(match[4]), reported_vote_percent=None if match[5] == '#Num!' else float(match[5][:-1]), source_row=int(match[6]), general_votes=None, postal_votes=None))
    candidates = result['candidates']
    if sorted(c['source_row'] for c in candidates) != list(range(1, len(candidates)+1)):
        issues.append('Candidate serial numbers are incomplete or duplicated.')
    if total:
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
            footer = re.search(r'rptDetailedResults - Page (\d+) of\s+(\d+)\b', text)
            if not footer: continue
            # Other PDF layouts include age, addresses and postal columns. Do not reinterpret them.
            if 'VALID VOTES POLLED' in text or 'GENERAL POSTAL' in text:
                raise ValueError('Different detailed-results layout')
            page_numbers.append(int(footer[1])); expected.add(int(footer[2]))
            text = text[:footer.start()]
            lines = [line.strip() for line in text.splitlines() if line.strip() and line.strip() not in {'DETAILED RESULTS','No.','CANDIDATE','SEX','PARTY','VOTES','%'} and not line.startswith('Election Commission of India')]
            cleaned = re.sub(r'[ \t]+NUMBER OF SEATS', '\nNUMBER OF SEATS', '\n'.join(lines)) + '\n'
            for match in IDENTITY.finditer(cleaned): pages[int(match[1])] = index+1
            chunks.append(cleaned)
    if len(expected) != 1: raise ValueError('No unambiguous supported detailed report')
    text = '\n'.join(chunks); identities = list(IDENTITY.finditer(text))
    codes = [int(m[1]) for m in identities]
    if not codes or len(codes) != len(set(codes)): raise ValueError('Missing or duplicated constituency identities')
    complete_pages = page_numbers == list(range(1, next(iter(expected))+1))
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
    details = [f for f in files if re.search(r'detailed\s+resul(?:ts|sts|t)\b', f['name'], re.I)]
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
    parser=argparse.ArgumentParser(); parser.add_argument('catalogue',type=Path); parser.add_argument('root',type=Path); parser.add_argument('--year',type=int); parser.add_argument('--report',type=Path); parser.add_argument('--layout',choices=['legacy','components','symbols','flat'],default='legacy'); args=parser.parse_args()
    extractor = extract
    if args.layout == 'components':
        from extract_assembly_components import extract as extractor
    if args.layout == 'symbols':
        from extract_assembly_symbols import extract as extractor
    if args.layout == 'flat':
        from extract_assembly_flat import extract as extractor
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
