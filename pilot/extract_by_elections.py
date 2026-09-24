"""Map preserved ECI by-election cells into source-linked constituency results."""
from datetime import date, datetime, timezone
import hashlib
import json
from pathlib import Path
import re


def text(value):
    return re.sub(r'\s+', ' ', str(value if value is not None else '')).strip()


def number(value):
    value = text(value).replace(',', '')
    return int(float(value)) if re.fullmatch(r'\d+(?:\.0+)?', value) else None


def last_number(cells):
    values = [v for v in cells if v is not None and text(v)]
    return number(values[-1]) if values else None


def finish(record):
    notes = record.setdefault('notes', [])
    candidates = record['candidates']
    if not record.get('state') or not record.get('constituency') or not record.get('kind'):
        notes.append('Source geographic identity is incomplete; no current constituency mapping is inferred.')
    if any(c['votes'] is None for c in candidates):
        notes.append('Some vote cells are missing, formulas or non-numeric; original cells are preserved.')
    if record.get('reported_contested') is not None and record['reported_contested'] != len([c for c in candidates if not c.get('nota')]):
        notes.append('Extracted candidate count differs from the reported number contested.')
    votes = [c['votes'] for c in candidates if not c.get('nota')]
    if votes and all(v is not None for v in votes) and record.get('reported_candidate_total') is not None and sum(votes) != record['reported_candidate_total']:
        notes.append('Candidate vote sum differs from the reported candidate total.')
    if any(not c.get('party') for c in candidates if not c.get('nota')):
        notes.append('Party information is missing in some source rows.')
    record['notes'] = list(dict.fromkeys(notes))
    record['status'] = 'needs_review' if record['notes'] else 'extracted'
    record['candidate_count'] = len(candidates)
    return record


def index_card(tables, year):
    rows = [row | {'table': table['name']} for table in tables for row in table['rows']]
    record = {'year': year, 'kind': None, 'state': None, 'constituency': None, 'candidates': [], 'metadata_rows': [], 'notes': []}
    for row in rows:
        line = ' '.join(text(v) for v in row['cells'] if text(v))
        heading = re.fullmatch(r'(\d+)\s*[-–]\s*(.+?)\s*\(([^()]+)\)', line)
        if heading and record['constituency'] is None:
            record.update(code=int(heading[1]), constituency=heading[2].strip(), state=heading[3].strip())
        kind = re.search(r'Valid Votes in (PC|AC)\b', line, re.I)
        if kind:
            record['kind'] = kind[1].lower()
        legacy_state = re.search(r'\bSTATE\s*[-:]\s*(.+?)(?:\s+(?:AC|PC)\s*[-:]|$)', line, re.I)
        if legacy_state and record['state'] is None:
            record['state'] = legacy_state[1].strip()
        house = re.fullmatch(r'House of the People of\s+(.+)', line, re.I)
        if house:
            record.update(kind='pc',state=house[1].strip())
        state_code = re.fullmatch(r'(.+?)\s+State Code\s*[-:]\s*([SU]\s*[-]?\s*\d+)', line, re.I)
        if state_code and record['state'] is None:
            record.update(state=state_code[1].strip(),source_state_code=state_code[2])
        if record['kind'] is None and re.match(r'^(?:Parliament(?:ary)?|Assembly)\s+Constituency\b', line, re.I):
            record['kind'] = 'pc' if line.lower().startswith('parliament') else 'ac'
        state = re.search(r'^(?:(?:Bye Election of|Election to)\s+)?(Assembly\s+Con\w+|Legislative\s+Assembly|Par\w*\s+Con\w+)\s+(?:of\s*[-:]?\s*|[-:]\s*)?(.+?)(?:,\s*District\b.*)?$', line, re.I)
        if state and record['state'] is None and len(state[2]) < 100 and not re.match(r'[-\d]', state[2]):
            record.update(kind='pc' if state[1].lower().startswith('par') else 'ac', state=state[2].strip())
        identity = re.search(r'Cons\w+\s*(?:of\s+)?[-:]?\s*(\d+)\s*[-.]?\s*(.+)$', line, re.I)
        if identity and record['constituency'] is None and 'polling' not in line.lower():
            record.update(code=int(identity[1]), constituency=identity[2].strip())
        name_only = re.fullmatch(r'Assembly\s+Con\w+\s*[-\u2013\u2014\ufffd]\s*([^\d].+)', line, re.I)
        if name_only and record['constituency'] is None:
            record['constituency'] = name_only[1].strip()
            record['notes'].append('The source heading supplies a constituency name without a readable constituency code.')
        if record['constituency'] is None and re.search(r'(?:number|no\.).*name', line, re.I):
            trailing = re.search(r'Cons\w+\s+(.+?)[-]\s*(\d+)\s*(\([^)]*\))?$', line, re.I)
            if trailing:
                record.update(code=int(trailing[2]), constituency=trailing[1].strip()+' '+(trailing[3] or ''))
        if len(row['cells']) > 1 and text(row['cells'][1]).lower() == 'contested':
            record['reported_contested'] = last_number(row['cells'][2:])
        if any(x in line.lower() for x in ['electors', 'dates', 'polling', 'counting', 'declaration', 'vacancy', 'reason thereof']):
            record['metadata_rows'].append(row)
    header_index = None
    for i, row in enumerate(rows):
        cells = [text(v).lower() for v in row['cells']]
        name = next((j for j, v in enumerate(cells) if v == 'name' or ('candidate' in v and 'detail' not in v and 'votes' not in v)), None)
        party = next((j for j, v in enumerate(cells) if 'party' in v), None)
        vote = next((j for j, v in enumerate(cells) if v == 'total valid votes'), None)
        if vote is None:
            vote = next((j for j, v in enumerate(cells) if v in ['votes', 'vote', 'valid votes polled', 'votes polled', 'number']), None)
        if row['table'] == '20-Mandya, KR,PC' and name == 1 and vote == 3 and party is None:
            party = 2
            record['notes'].append('Party column heading contains a party name and the source party cells appear shifted. Party values are shown exactly as printed and require official correction.')
        if row['table'] == '27-Kolaras(AC)-MP' and party == 2 and vote == 3 and name is None:
            name = 1
            record['notes'].append('Candidate heading is misplaced in the official workbook; names are read from the candidate column below it.')
        if name is not None and party is not None and vote is not None:
            header_index, name_col, party_col, vote_col = i, name, party, vote
            if vote_col + 2 < len(cells) and i+1 < len(rows) and text(rows[i+1]['cells'][vote_col+2]).lower() == 'total':
                vote_col += 2
            break
    if header_index is None:
        uncontested = [row for row in rows if any('UNCONTESTED' in text(v).upper() for v in row['cells'])]
        if uncontested and record['constituency']:
            record['notes'].append('The source reports an uncontested election but does not name the elected candidate in this table. No winner or vote total is inferred.')
            record['metadata_rows'].extend(uncontested)
            record['election_status'] = 'reported_uncontested'
            return finish(record)
        return None
    for row in rows[header_index+1:]:
        cells = row['cells']
        padded = cells + [None]*max(0, vote_col+1-len(cells))
        if any(text(v).lower() in ['total', 'grand total'] for v in cells[:2]):
            record['reported_candidate_total'] = number(padded[vote_col])
            break
        if text(cells[0]).lower() == 'total valid votes' and len(cells) > 1:
            record['reported_candidate_total'] = number(cells[1])
            break
        if len(cells) <= name_col or not text(cells[name_col]):
            continue
        serial = next((value for value in cells[:name_col] if text(value)), None)
        if number(text(serial).rstrip('.')) is None and text(cells[name_col]).upper() not in ['NOTA', 'NONE OF THE ABOVE']:
            continue
        record['candidates'].append({'name': text(cells[name_col]), 'party': text(padded[party_col]), 'votes': number(padded[vote_col]),
                                     'raw_votes': padded[vote_col], 'source_row': row['row'], 'table': row['table'], 'source_cells': cells,
                                     'nota': text(cells[name_col]).upper() in ['NOTA', 'NONE OF THE ABOVE']})
    if not record['candidates']:
        return None
    return finish(record)


def historical_summary(table):
    kind = 'pc' if 'lok' in table['name'].lower() else 'ac'
    records, current, state, year = [], None, None, None
    source_date, source_year_text, date_warning = None, None, None
    for row in table['rows']:
        cells = row['cells']+[None]*13
        if row['row'] <= 5:
            continue
        new_year = number(cells[2])
        if new_year and 1950 <= new_year <= 1995:
            year = new_year
            source_year_text, source_date, date_warning = text(cells[2]), None, None
        else:
            date_text = text(cells[2])
            match = re.fullmatch(r'(\d{1,2})[./-](\d{1,2})[./-](\d{2}|\d{4})', date_text)
            if match:
                source_year_text = date_text
                try:
                    date_year = int(match[3])+(1900 if len(match[3]) == 2 else 0)
                    if not 1952 <= date_year <= 1995:
                        raise ValueError('Outside the source period')
                    parsed = date(date_year,int(match[2]),int(match[1]))
                    year, source_date, date_warning = parsed.year, parsed.isoformat(), None
                except ValueError:
                    year, source_date, date_warning = None, None, 'The source date is invalid; its election year has not been inferred.'
            elif re.match(r'^\d{1,2}[./-]\d', date_text):
                source_year_text = date_text
                year, source_date, date_warning = None, None, 'The source date format is unclear; its election year has not been inferred.'
        if text(cells[0]):
            state = text(cells[0])
        if text(cells[3]) and text(cells[3]) != '-':
            if current:
                records.append(finish(current))
            current = {'kind': kind, 'year': year, 'state': state, 'constituency': text(cells[3]), 'source_row': row['row'],
                       'candidates': [], 'notes': ['Historical winner/runner summary; complete candidate coverage is not established. State labels and constituency names remain as recorded.'],
                       'reason': text(cells[10]), 'table': table['name'], 'source_date':source_date, 'source_year_text':source_year_text}
            if date_warning:
                current['notes'].append(date_warning)
        if current is None:
            continue
        if text(cells[3]) == '-':
            current['notes'].append('Additional member/result row appears under this constituency; no single-seat winner or margin is inferred.')
        for party_col, votes_col, name_col, role in [(4, 5, 6, 'reported_winner'), (7, 8, 9, 'reported_other')]:
            if text(cells[name_col]):
                current['candidates'].append({'name': text(cells[name_col]), 'party': text(cells[party_col]), 'votes': number(cells[votes_col]),
                                             'raw_votes': cells[votes_col], 'role': role, 'source_row': row['row'], 'table': table['name'],
                                             'source_cells': row['cells']})
    if current:
        records.append(finish(current))
    return records


def source_navigation_table(tables):
    rows = [row['cells'] for table in tables for row in table['rows']]
    if not rows or any(len(row) > 2 for row in rows):
        return False
    lines = [' '.join(text(cell) for cell in row if text(cell)) for row in rows]
    if not any(re.search(r'\bSTATE\b.*\bCONSTITUENCY\b', line, re.I) for line in lines):
        return False
    if any(re.search(r'\b(?:votes?|candidates?|party|winner)\b', line, re.I) for line in lines):
        return False
    return sum(bool(re.search(r'\b\d{1,3}\s*[-–]\s*[A-Za-z]', line)) for line in lines) >= 2


def build(root):
    catalogue = json.loads((root/'catalogue.json').read_text(encoding='utf-8'))
    records, unmapped = [], []
    for edition in catalogue['entries']:
        folder = root/edition['id']
        if not (folder/'manifest.json').exists():
            continue
        manifest = json.loads((folder/'manifest.json').read_text(encoding='utf-8'))
        for entry in manifest.get('extractions', []):
            path = folder/entry['file']
            payload = path.read_bytes()
            if hashlib.sha256(payload).hexdigest() != entry['sha256']:
                raise ValueError('Extraction checksum changed: '+str(path))
            data = json.loads(payload)
            provenance = {'edition': edition['id'], 'period': edition['label'], 'source_url': data['source_url'], 'source_file': data['source_file'],
                          'source_sha256': data['source_sha256'], 'raw_table_file': entry['file']}
            if edition['year'] == 1952:
                groups = [(table['name'], historical_summary(table)) for table in data['tables']]
            elif data['source_file'].endswith('.html'):
                groups = [('HTML document', [index_card(data['tables'], edition['year'])])]
            else:
                groups = [(table['name'], [index_card([table], edition['year'])]) for table in data['tables']]
            for table_name, mapped in groups:
                mapped = [record for record in mapped if record]
                if not mapped:
                    selected = data['tables'] if table_name == 'HTML document' else [t for t in data['tables'] if t['name'] == table_name]
                    values = [text(cell) for t in selected for row in t['rows'] for cell in row['cells'] if text(cell)]
                    status = 'blank_source_sheet' if not values else 'needs_mapping_or_supporting_table'
                    if data['source_file'].endswith('.html') and (re.search(r'/index\.html?$', data['source_url'], re.I) or any('BACKGROUND INFORMATION' in value for value in values) or source_navigation_table(selected)):
                        status = 'source_navigation_table'
                    unmapped.append(provenance | {'table': table_name, 'status': status})
                for index, record in enumerate(mapped):
                    record.update(provenance)
                    record['id'] = hashlib.sha256((edition['id']+entry['file']+table_name+str(index)).encode()).hexdigest()[:24]
                    records.append(record)
    result = {'adapter': 'eci-by-election-cells-v1', 'built_at': datetime.now(timezone.utc).isoformat(), 'records': records, 'unmapped': unmapped,
              'scope_note': 'Source editions may overlap. Historical identities are not current constituency mappings. Blank or formula vote cells remain missing, never zero.'}
    body = json.dumps(result, ensure_ascii=False, indent=2).encode('utf-8')
    destination = root/'results.json'
    if destination.exists():
        previous = destination.read_bytes()
        (root/('results-'+hashlib.sha256(previous).hexdigest()+'.json')).write_bytes(previous)
    destination.write_bytes(body)
    published = root/'structured'
    published.mkdir(exist_ok=True)
    index = []
    for record in records:
        payload = json.dumps(record, ensure_ascii=False).encode('utf-8')
        digest = hashlib.sha256(payload).hexdigest()
        filename = record['id']+'-'+digest[:16]+'.json'
        (published/filename).write_bytes(payload)
        index.append({k:record.get(k) for k in ['id', 'year', 'kind', 'state', 'constituency', 'period', 'edition', 'candidate_count', 'status']} |
                     {'file': filename, 'sha256': digest, 'note_count': len(record['notes'])})
    temporary = published/'index.tmp'
    temporary.write_text(json.dumps({'records': index, 'built_at': result['built_at'], 'scope_note': result['scope_note'], 'unmapped_tables': sum(t['status'] == 'needs_mapping_or_supporting_table' for t in unmapped), 'supporting_tables':len(unmapped)}, ensure_ascii=False), encoding='utf-8')
    temporary.replace(published/'index.json')
    print(json.dumps({'records': len(records), 'candidate_rows': sum(len(r['candidates']) for r in records),
                      'unmapped_tables': sum(t['status'] == 'needs_mapping_or_supporting_table' for t in unmapped),
                      'supporting_tables': len(unmapped), 'records_with_notes': sum(bool(r['notes']) for r in records)}))


if __name__ == '__main__':
    build(Path(__file__).resolve().parents[1]/'application/storage/app/private/election-by-elections')
