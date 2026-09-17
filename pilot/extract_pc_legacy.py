"""State-scoped extraction of the ECI's 1951–1999 Lok Sabha editions."""
import hashlib
import json
import re
from pathlib import Path

import fitz


def normal(value):
    return re.sub(r'[^A-Z0-9]', '', value.upper())


def sorted_1967_text(page):
    """Restore table reading order without joining cells across candidate rows."""
    lines=[]
    for line in page.get_text(sort=True).splitlines():
        line=line.strip()
        if not line:continue
        if line.startswith('No.') and 'CANDIDATE' in line:
            lines.append('%');continue
        candidate=re.fullmatch(r'(\d+)\s*\.\s*(.+?)\s+([MF])\s+(\S+)\s+(\d+)\s+([\d.]+%|#Num!)',line)
        if candidate:
            serial,name,sex,party,votes,percent=candidate.groups()
            lines.append(f'{serial} . {name} {sex}\n{party}\n{votes}\n{percent}')
        elif line.startswith('ELECTORS'):
            lines.append(re.sub(r'(ELECTORS|VOTERS|VALID VOTES)\s*:\s*(\d+)',r'\1 :\n\2\n',line))
        else:lines.append(line)
    return '\n'.join(lines)


def summary_figures(page, year):
    """Read labelled numeric cells, rejecting missing or ambiguous source cells."""
    words=page.get_text('words')
    def value(label,left,right,top,bottom,label_left=100,label_right=160,optional=False):
        labels=[w for w in words if w[4]==label and label_left<w[0]<label_right and top<w[1]<bottom]
        if len(labels)!=1:raise ValueError('Summary label missing or ambiguous: '+label)
        cells=[w[4] for w in words if left<w[0]<right and abs(w[1]-labels[0][1])<2 and w[4].isdigit()]
        if not cells and optional:return None
        if len(cells)!=1:raise ValueError('Summary value missing or ambiguous: '+label)
        return int(cells[0])
    early=year<1962
    return dict(electors=value('TOTAL',450,520,295,315) if early else value('TOTAL',450,520,325,355),
                votes_polled=value('TOTAL',450,520,330,355) if early else value('TOTAL',450,520,400,435),
                valid_candidate_votes=value('VALID',250,330,395,425) if early else value('VALID',250,330,460,490),
                contested=value('CONTESTED',450,520,230,265,optional=True),
                winner_votes=value('Winner',450,530,490,740,75,100,optional=True),
                runner_votes=value('Runner',450,530,490,740,75,100,optional=True),
                margin=value('MARGIN',145,195,540,740,75,100,optional=True))


def reconcile(record, figures, issues):
    issues=list(issues)
    record.pop('winner',None);record.pop('margin',None);record.pop('error',None)
    if isinstance(figures,str):issues.append(figures)
    elif figures:
        record['summary_totals']={k:figures[k] for k in ['electors','votes_polled','valid_candidate_votes']}
        for key in record['summary_totals']:
            if record.get(key)!=figures[key]:issues.append('Detailed and summary '+key.replace('_',' ')+' differ')
        candidates=record['candidates']
        if figures['contested'] is not None and len(candidates)!=figures['contested']:issues.append('Candidate count differs from the summary')
        ranked=sorted(candidates,key=lambda c:c['votes'],reverse=True)
        if len(ranked)<2 or ranked[0]['votes']==ranked[1]['votes']:issues.append('Winner requires review')
        else:
            margin=ranked[0]['votes']-ranked[1]['votes']
            if figures['winner_votes']!=ranked[0]['votes'] or figures['runner_votes']!=ranked[1]['votes'] or figures['margin']!=margin:issues.append('Winner, runner-up or margin differs from the summary or is unreported')
            if not issues:record.update(winner=ranked[0]['candidate_name'],margin=margin)
    else:issues.append('Independent summary totals could not be reconciled')
    if issues:record.update(status='needs_review',error='; '.join(dict.fromkeys(issues)))
    else:record.update(status='validated')


def summary_identity(text):
    patterns = [
        r'([^\n]+)\nSTATE/UT\s*:\nCONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)\nCODE\s*:\n([SU]\d+)',
        r'([^\n]+)\nSTATE/UT\s*:\nCONSTITUENCY\s*:\n([^\n]+)\nCODE\s*:\nNO\s*:\n([SU]\d+)\n(\d+)',
        r'([^\n]+)\nSTATE/UT\s*:\nCODE\s*:\nNO\s*:\n([SU]\d+)\n(\d+)\nCONSTITUENCY\s*:\n([^\n]+)',
        r'([^\n]+)\nSTATE/UT\s*:\nCODE\s*:\nNO\s*:\n([SU]\d+)\n(\d+)\n([^\n]+)\nCONSTITUENCY\s*:',
    ]
    for index, pattern in enumerate(patterns):
        m = re.search(pattern, text)
        if m:
            if index == 0: return m[1], m[4], int(m[2]), m[3]
            if index == 1: return m[1], m[3], int(m[4]), m[2]
            return m[1], m[2], int(m[3]), m[4]
    raise ValueError('Unrecognized summary identity')


def extract(detail, summary, year):
    summaries = {}; states = {}; summary_errors = []
    with fitz.open(summary) as doc:
        for i, page in enumerate(doc):
            text = '\n'.join(x.strip() for x in page.get_text().splitlines() if x.strip())
            if 'rptConstituencySummary' not in text: continue
            try:
                state, state_code, code, name = summary_identity(text)
                key = (normal(state), code)
                if key in summaries: raise ValueError('Duplicate summary identity')
                try:figures=summary_figures(page,year)
                except ValueError as error:figures=str(error)
                summaries[key] = (name, state_code, i + 1, figures)
                states[normal(state)] = state
            except ValueError as error: summary_errors.append(f'Page {i+1}: {error}')
    chunks = []; offsets = []; length = 0; page_numbers = []; declared = set()
    headers = {'No.', 'CANDIDATE', 'CANDIDATES', 'SEX', 'PARTY', 'VOTES', '%', 'DETAILED RESULTS'}
    with fitz.open(detail) as doc:
        for i, page in enumerate(doc):
            text = sorted_1967_text(page) if year==1967 else page.get_text()
            footer = re.search(r'rptDetailedResults\s*-?\s*(\d+) of\s+(\d+)', text)
            if not footer: continue
            page_numbers.append(int(footer[1])); declared.add(int(footer[2]))
            page_body=text[:footer.start()]
            if year==1967:
                trailing=[x.strip() for x in text[footer.end():].splitlines() if re.fullmatch(r'[A-Za-z &]+',x.strip())]
                if trailing:
                    last=page_body.rfind('Constituency')
                    if last<0:raise ValueError('Trailing state has no constituency')
                    page_body=page_body[:last]+trailing[0]+'\n'+page_body[last:]
            lines = [x.strip() for x in page_body.splitlines() if x.strip()]
            if year==1967:
                normalized='\n'.join(lines)
                normalized=re.sub(r'(Constituency\s*:\s*)1\s*\.\n(\d+\s*\.\s*[^\n]+)\n',r'\1\2\n1 . ',normalized)
                for known_state in states.values():
                    normalized=re.sub(r'(Constituency\s*:\s*\d+\s*\.\s*[^\n]+)\n'+re.escape(known_state)+r'\n',lambda m:known_state+'\n'+m[1]+'\n',normalized,flags=re.I)
                lines=normalized.splitlines()
            # Every detailed page carries its state heading immediately after the column headings.
            if '%' not in lines: raise ValueError(f'Missing state header on PDF page {i+1}')
            heading_index = lines.index('%') + 1
            while lines[heading_index] in headers or lines[heading_index].startswith('GENERAL ELECTIONS'):
                heading_index += 1
            body_start = heading_index+1
            if year == 1967 and normal(lines[heading_index]) not in states:
                body_start = heading_index
                heading_index = next((n for n in range(heading_index, min(len(lines),heading_index+12)) if normal(lines[n]) in states), heading_index)
            state = lines[heading_index]
            if ':' in state:raise ValueError(f'Unrecognized state heading on PDF page {i+1}')
            if year==1967:
                lines.pop(heading_index)
                body_start=0
            if normal(state) not in states:
                # Retain the source's literal state instead of guessing a current/state-code mapping.
                states[normal(state)] = state
            offsets.append((length, i+1, state))
            kept = []
            remaining = lines[body_start:]
            for line_index, line in enumerate(remaining):
                if (year==1967 and (normal(line) in states or (line_index>0 and 'POLL PERCENTAGE' in remaining[line_index-1] and re.fullmatch(r'[A-Za-z &]+',line)))) or (re.fullmatch(r'[A-Za-z &.,()\-]+', line) and line_index+1 < len(remaining) and remaining[line_index+1].startswith('Constituency') and 'PERCENTAGE' not in line):
                    state = line
                    offsets.append((length + sum(len(x)+1 for x in kept), i+1, state))
                elif line not in headers and not line.startswith('GENERAL ELECTIONS') and not line.startswith('Election Commission'):
                    kept.append(line)
            cleaned = '\n'.join(kept)
            chunks.append(cleaned); length += len(cleaned)+1
    if len(declared) != 1 or page_numbers != list(range(1, next(iter(declared))+1)):
        raise ValueError('Detailed report pages are incomplete or out of order')
    text = '\n'.join(chunks)
    identity_pattern=r'(?m)^Constituency\s*:?\s*(\d+)\s*(?:\.\s*|\n)([^\n]+)\n'
    if year<1962:identity_pattern=r'(?m)^Constituency\s*:?\s*(\d+)\s*(?:\.\s*|\n)(.+?)\n(?=\d+\nNUMBER OF SEATS\n)'
    matches = list(re.finditer(identity_pattern, text,re.S if year<1962 else 0))
    records = []; seen = set()
    for index, match in enumerate(matches):
        _, page, state = [o for o in offsets if o[0] <= match.start()][-1]
        code = int(match[1]); name = ' '.join(match[2].split()); key = (normal(state), code)
        if key in seen: raise ValueError(f'Duplicate detailed identity: {state}/{code} ({name}, page {page})')
        seen.add(key)
        record = dict(code=index+1, official_pc_code=code, state_name=state, name=f'{state} / {name}', constituency_name=name, detail_page=page, number_of_seats=1, candidates=[])
        records.append(record); issues = []
        paired = summaries.get(key)
        if paired:
            record.update(state_code=paired[1], summary_page=paired[2])
            if normal(name) != normal(paired[0]): issues.append('Detailed and summary constituency names differ')
        else: issues.append('Matching state/constituency summary is unavailable')
        body = text[match.end():matches[index+1].start() if index+1<len(matches) else len(text)]
        seats = re.match(r'(\d+)\nNUMBER OF SEATS\n', body)
        if seats: record['number_of_seats'] = int(seats[1]); body = body[seats.end():]
        candidate_text = re.split(r'ELECTORS\s*:', body)[0].strip()
        for block in re.split(r'(?m)^(?=\d+\s*\.\s)', candidate_text):
            if not block.strip(): continue
            pattern = r'(\d+)\s*\.\s*(.+?)(?:\s+([MF]))?\n([^\n]+)\n(\d+)\n(?:[\d.]+%|#Num!)(?:\nRETURNED UNCONTESTED)?(?:\n([A-Z][A-Z .()\'-]+))?\s*' if year >= 1962 else r'(\d+)\s*\.\s*(.+?)\n([^\n]+)\n(\d+)\n(?:[\d.]+%|#Num!)(?:\nRETURNED UNCONTESTED)?\s*'
            m = re.fullmatch(pattern, block, re.S)
            if not m:
                record.setdefault('unparsed_candidate_rows',[]).append(block.strip())
                issues.append('Candidate row layout requires review'); continue
            party, votes = (m[4], m[5]) if year >= 1962 else (m[3], m[4])
            candidate_name=' '.join(m[2].split())
            if year>=1962 and m[6]:candidate_name+=' '+m[6].strip()
            record['candidates'].append(dict(source_row=int(m[1]), candidate_name=candidate_name, party_at_election=party, votes=int(votes), general_votes=None, postal_votes=None))
        for label, field in [('ELECTORS', 'electors'), ('VOTERS', 'votes_polled'), ('VALID VOTES', 'valid_candidate_votes')]:
            m = re.search(label+r'\s*:?\s*\n(\d+)', body)
            if m: record[field] = int(m[1])
            else: issues.append(f'{label} total is missing')
        cs = record['candidates']
        if sorted(c['source_row'] for c in cs) != list(range(1,len(cs)+1)): issues.append('Candidate serial numbers are incomplete')
        if sum(c['votes'] for c in cs) != record.get('valid_candidate_votes'): issues.append('Candidate votes do not reconcile with the detailed total')
        if record['number_of_seats'] != 1: issues.append('Multi-member constituency: individual winners require review')
        elif not 0 < record.get('valid_candidate_votes', 0) <= record.get('votes_polled', 0) <= record.get('electors', 0): issues.append('Uncontested or inconsistent elector/voter totals')
        reconcile(record,paired[3] if paired else None,issues)
    if not records: raise ValueError('No detailed constituencies extracted')
    missing = [dict(state_name=states[state],official_pc_code=code,constituency_name=value[0],summary_page=value[2]) for (state,code),value in summaries.items() if (state,code) not in seen]
    return records, dict(summary_identity_errors=summary_errors, summary_count=len(summaries), detailed_count=len(records), detailed_pages=len(page_numbers), unmatched_summaries=missing)


def run(manifest_path):
    path = Path(manifest_path); manifest = json.loads(path.read_text(encoding='utf-8'))
    year = manifest['year']
    if manifest['kind'] != 'pc' or not 1951 <= year <= 1999: raise ValueError('Unsupported legacy edition')
    sources = []
    for volume in ['I', 'II']:
        matches = [f for f in manifest['files'] if re.search(r'Vol[ -]*'+volume+r'\)', f['name'], re.I)]
        if len(matches) != 1: raise ValueError(f'Missing or ambiguous volume {volume}')
        source = matches[0]
        if Path(source['file']).name != source['file']: raise ValueError('Invalid source filename')
        if hashlib.sha256((path.parent/source['file']).read_bytes()).hexdigest() != source['sha256']: raise ValueError('Source checksum differs')
        sources.append(source)
    records, coverage = extract(*(path.parent/f['file'] for f in sources), year)
    validated=sum(r['status']=='validated' for r in records)
    result = dict(adapter='eci-pc-legacy-v2', kind='pc', year=year, source_url=manifest['url'], source_file=sources[0]['file'], source_sha256=sources[0]['sha256'], additional_sources=sources[1:], records=records, validated_count=validated, review_count=len(records)-validated, coverage=coverage, coverage_note=f'{year} official Lok Sabha edition: {len(records)} constituency records. Special editions retain their limited source coverage. Dagger notes identify pending checks. Historical mapping and publication are pending.')
    temporary = path.parent/'extraction.tmp'; temporary.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8'); temporary.replace(path.parent/'extraction.json')
    return result


if __name__ == '__main__':
    import argparse
    parser = argparse.ArgumentParser(); parser.add_argument('manifest'); args = parser.parse_args()
    result = run(args.manifest)
    print(json.dumps({k:result[k] for k in ['year','validated_count','review_count','coverage']}))
