"""Extract collected modern Assembly workbooks with source-level reconciliation."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import openpyxl


def normal(value):
    return re.sub(r"[^a-z0-9]", "", re.sub(r"\((?:GEN|SC|ST|BL)\)", "", str(value), flags=re.I).lower())


def count(value):
    return int(value) if isinstance(value, (int, float)) and not isinstance(value, bool) and value >= 0 and int(value) == value else None


def extract(detail, summary, state):
    records = {}
    notes = []
    book = openpyxl.load_workbook(detail, read_only=True, data_only=False)
    try:
        rows = iter(book.active.values)
        for _ in range(3): next(rows)
        header = list(next(rows))
        if header != ['STATE/UT NAME', 'AC NO.', 'AC NAME', 'CANDIDATE NAME', 'GENDER', 'AGE', 'CATEGORY', 'PARTY', 'SYMBOL', 'GENERAL', 'POSTAL', 'TOTAL', 'OVER VALID VOTES + NOTA', 'OVER TOTAL ELECTORS', 'TOTAL ELECTORS']:
            raise ValueError('Detailed headers changed')
        current = None
        for index, row in enumerate(rows, 5):
            if not any(v is not None for v in row): continue
            if row[0] == 'Disclaimer': break
            if isinstance(row[0], str) and row[0].startswith('*') and all(v is None for v in row[1:]):
                notes.append(row[0])
                continue
            if row[0] == 'GRAND TOTAL:': continue
            if row[0] == 'TURN OUT':
                if current is None: raise ValueError('Total without constituency')
                current['detail_totals'] = [count(v) for v in row[9:12]]
                continue
            if normal(row[0]) != normal(state) or count(row[1]) is None:
                raise ValueError('Unrecognised geography at row ' + str(index))
            code = int(row[1])
            if code not in records:
                records[code] = dict(code=code, state_name=state, name=row[2], constituency_name=row[2], number_of_seats=1, candidates=[], electors=count(row[14]), issues=[], source_locator=f'{book.active.title}, row {index}')
            current = records[code]
            if current['name'] != row[2] or current['electors'] != count(row[14]):
                current['issues'].append('Repeated constituency name or elector total differs.')
            match = re.fullmatch(r'(\d+)\s+(.+)', str(row[3]))
            if not match: raise ValueError('Candidate serial/name format changed at row ' + str(index))
            candidate = dict(source_row=int(match[1]), workbook_row=index, candidate_name=match[2], party_at_election=str(row[7]), is_nota=str(row[7]).upper() == 'NOTA', general_votes=count(row[9]), postal_votes=count(row[10]), votes=count(row[11]), source_values=list(row))
            current['candidates'].append(candidate)
    finally: book.close()
    book = openpyxl.load_workbook(summary, read_only=True, data_only=False)
    seen = set()
    try:
        for sheet in book:
            rows = list(sheet.values)
            identity = re.fullmatch(r'(\d+)-(.+)', str(rows[1][3]))
            state_identity = re.fullmatch(r'([SU]\d+)-(.+)', str(rows[1][1]))
            if not identity or not state_identity or normal(state_identity[2]) != normal(state):
                raise ValueError('Summary identity changed')
            code = int(identity[1])
            if code in seen: raise ValueError('Duplicate summary constituency')
            seen.add(code)
            if code not in records:
                records[code] = dict(code=code, state_name=state, name=identity[2], constituency_name=identity[2], number_of_seats=1, candidates=[], electors=None, issues=['Detailed candidate rows are absent; check the official summary, including any uncontested result.'], source_locator='No detailed workbook rows')
            record = records[code]
            record['state_code'] = state_identity[1]
            record['summary_locator'] = 'Summary sheet ' + sheet.title
            record['summary_source_rows'] = rows
            if normal(record['name']) != normal(identity[2]):
                record['issues'].append('Detailed and summary constituency names differ; totals were not attached.')
                continue
            def field(label):
                matches = [r for r in rows if str(r[1]).strip().lower() == label.lower()]
                return count(matches[0][5]) if len(matches) == 1 else None
            electors = field('4. Total')
            polled = field('5. Total')
            valid = field('7. Total Valid Votes Polled')
            record['summary_totals'] = dict(electors=electors, votes_polled=polled, valid_candidate_votes=valid)
            record['votes_polled'] = polled
            record['valid_candidate_votes'] = valid
            if record['electors'] is None: record['electors'] = electors
            if record['electors'] != electors: record['issues'].append('Detailed and summary elector totals differ.')
            if None in [electors, polled, valid]: record['issues'].append('One or more summary totals are missing or non-numeric.')
            cs = record['candidates']
            if cs and all(c['votes'] is not None for c in cs):
                if sum(c['votes'] for c in cs if not c['is_nota']) != valid: record['issues'].append('Candidate votes excluding NOTA differ from the summary valid total.')
                if polled is not None and sum(c['votes'] for c in cs) > polled: record['issues'].append('Candidate votes plus NOTA exceed summary votes polled.')
            if electors is not None and polled is not None and polled > electors: record['issues'].append('Votes polled exceed electors.')
            ranked = sorted([c for c in cs if not c['is_nota'] and c['votes'] is not None], key=lambda c:c['votes'], reverse=True)
            winners = [r for r in rows if str(r[1]).strip().lower() == 'winner']
            margins = [r for r in rows if str(r[1]).strip().lower() == 'margin']
            if len(ranked) >= 2 and len(winners) == 1 and len(margins) == 1 and ranked[0]['votes'] > ranked[1]['votes']:
                margin = ranked[0]['votes'] - ranked[1]['votes']
                if normal(winners[0][4]) == normal(ranked[0]['candidate_name']) and count(winners[0][5]) == ranked[0]['votes'] and count(margins[0][3]) == margin:
                    record.update(winner=ranked[0]['candidate_name'], margin=margin)
                else: record['issues'].append('Winner or margin differs between the detailed and summary reports.')
    finally: book.close()
    for code, record in records.items():
        cs = record['candidates']
        if code not in seen: record['issues'].append('No matching summary sheet.')
        if [c['source_row'] for c in cs] != list(range(1, len(cs)+1)): record['issues'].append('Candidate serial numbers are incomplete or duplicated.')
        if any(None in [c['general_votes'],c['postal_votes'],c['votes']] for c in cs): record['issues'].append('Candidate vote cells are missing or non-numeric; original cells are preserved.')
        elif cs:
            if any(c['general_votes'] + c['postal_votes'] != c['votes'] for c in cs): record['issues'].append('Candidate vote components differ.')
            totals = [sum(c[k] for c in cs) for k in ['general_votes','postal_votes','votes']]
            if totals != record.get('detail_totals'): record['issues'].append('Candidate rows do not reconcile with detailed totals.')
        issues = list(dict.fromkeys(record.pop('issues')))
        record.update(status='needs_review' if issues else 'validated', error='; '.join(issues), edition_notes=notes)
    return sorted(records.values(), key=lambda r:r['code'])


def run(entry, root):
    folder = root / hashlib.sha256(entry['url'].encode()).hexdigest()[:24]
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if manifest['url'] != entry['url']: raise ValueError('Manifest URL differs')
    def source(prefix):
        matches = [f for f in manifest['files'] if f['name'].startswith(prefix) and f['file'].endswith('.xlsx')]
        if len(matches) != 1: raise ValueError('Expected one workbook for report ' + prefix)
        item = matches[0]; path = folder / item['file']
        if Path(item['file']).name != item['file'] or hashlib.sha256(path.read_bytes()).hexdigest() != item['sha256']: raise ValueError('Source integrity failed')
        return item, path
    detail, detail_path = source('10-')
    summary, summary_path = source('8-')
    records = extract(detail_path, summary_path, entry['state'])
    data = dict(kind='ac', year=entry['year'], source_url=entry['url'], source_file=detail['file'], source_sha256=detail['sha256'], extracted_at=datetime.now(timezone.utc).isoformat(), additional_sources=[summary], records=records)
    output = folder / 'extraction.json'
    if output.exists():
        previous = output.read_bytes(); backup = folder / ('extraction-' + hashlib.sha256(previous).hexdigest() + '.json')
        if not backup.exists(): backup.write_bytes(previous)
    temporary = folder / 'extraction.tmp'; temporary.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding='utf-8'); temporary.replace(output)
    print(entry['state'], len(records), 'tables;', sum(r['status'] != 'validated' for r in records), 'with notes', flush=True)


if __name__ == '__main__':
    parser=argparse.ArgumentParser();parser.add_argument('catalogue',type=Path);parser.add_argument('root',type=Path);parser.add_argument('--year',type=int,required=True);args=parser.parse_args()
    for entry in json.loads(args.catalogue.read_text(encoding='utf-8'))['entries']:
        if entry['year'] == args.year: run(entry,args.root)
