"""Extract collected modern Assembly workbooks with source-level reconciliation."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import openpyxl
import xml.etree.ElementTree as ET
import posixpath
import sys
import io
from types import SimpleNamespace
from extract_election_import import safe_zip


class CellWorkbook(list):
    @property
    def active(self): return self[0]
    def close(self): pass


def load_cells(path):
    if Path(path).suffix.lower() == '.xls':
        try:
            import xlrd
        except ImportError:
            sys.path.insert(0, str(Path(__file__).parent / 'tmp/python-libs'))
            import xlrd
        source_notes = []
        try:
            source = xlrd.open_workbook(str(path), logfile=io.StringIO())
        except xlrd.compdoc.CompDocError:
            source = xlrd.open_workbook(str(path), logfile=io.StringIO(), ignore_workbook_corruption=True)
            source_notes.append('The official workbook has structural inconsistencies. Stored cell values are retained; check the original PDF and statutory forms.')
        try:
            result = CellWorkbook()
            result.reader_notes = source_notes
            for sheet in source.sheets():
                if sheet.nrows > 20000 or sheet.ncols > 100: raise ValueError('XLS dimensions exceed limits')
                rows = [[None if c.ctype in [xlrd.XL_CELL_EMPTY, xlrd.XL_CELL_BLANK] else c.value for c in sheet.row(i)] for i in range(sheet.nrows)]
                result.append(SimpleNamespace(title=sheet.name, values=rows))
            return result
        finally: source.release_resources()
    try:
        return openpyxl.load_workbook(path, read_only=True, data_only=False)
    except ValueError as error:
        if 'stylesheet' not in str(error): raise
    ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    book = CellWorkbook()
    with safe_zip(path) as archive:
        strings = [''.join(t.text or '' for t in cell.findall('.//s:t', ns)) for cell in ET.fromstring(archive.read('xl/sharedStrings.xml')).findall('s:si', ns)] if 'xl/sharedStrings.xml' in archive.namelist() else []
        links = {r.get('Id'): r.get('Target') for r in ET.fromstring(archive.read('xl/_rels/workbook.xml.rels')) if r.get('TargetMode') != 'External'}
        sheets = ET.fromstring(archive.read('xl/workbook.xml')).findall('s:sheets/s:sheet', ns)
        for sheet in sheets:
            target = links[sheet.get('{http://schemas.openxmlformats.org/officeDocument/2006/relationships}id')]
            target = posixpath.normpath(target.lstrip('/') if target.startswith('/') else 'xl/' + target)
            if not target.startswith('xl/worksheets/'): raise ValueError('Unexpected worksheet path')
            rows = []
            for row in ET.fromstring(archive.read(target)).findall('s:sheetData/s:row', ns):
                index = int(row.get('r'))
                if index > 20000: raise ValueError('Worksheet exceeds row limit')
                while len(rows) < index: rows.append([])
                for cell in row.findall('s:c', ns):
                    column = openpyxl.utils.column_index_from_string(re.match(r'[A-Z]+', cell.get('r'))[0])
                    if column > 100: raise ValueError('Worksheet exceeds column limit')
                    while len(rows[index-1]) < column: rows[index-1].append(None)
                    value = cell.findtext('s:v', default='', namespaces=ns)
                    formula = cell.find('s:f', ns)
                    if formula is not None: value = '=' + (formula.text or '')
                    elif cell.get('t') == 's': value = strings[int(value)]
                    elif cell.get('t') == 'inlineStr': value = ''.join(t.text or '' for t in cell.findall('.//s:t', ns))
                    elif value and cell.get('t') not in ['str','e','b']: value = float(value)
                    else: value = value or None
                    rows[index-1][column-1] = value
            width = max(map(len, rows), default=0)
            book.append(SimpleNamespace(title=sheet.get('name'), values=[r + [None]*(width-len(r)) for r in rows]))
    return book


def normal(value):
    return re.sub(r"[^a-z0-9]", "", re.sub(r"\((?:GEN|SC|ST|BL)\)", "", str(value), flags=re.I).lower())


def state_matches(value, catalogue_state):
    allowed = {'Delhi': ['Delhi', 'NCT of Delhi']}.get(catalogue_state, [catalogue_state])
    return normal(value) in [normal(v) for v in allowed]


def count(value):
    return int(value) if isinstance(value, (int, float)) and not isinstance(value, bool) and value >= 0 and int(value) == value else None


def extract(detail, summary, state):
    records = {}
    notes = []
    book = load_cells(detail)
    try:
        rows = iter(book.active.values)
        header = None
        for header_index in range(1, 7):
            candidate_header = [v.strip() if isinstance(v, str) else v for v in next(rows)]
            if candidate_header[:4] == ['STATE/UT NAME', 'AC NO.', 'AC NAME', 'CANDIDATE NAME']:
                header = candidate_header
                break
        expected = ['STATE/UT NAME', 'AC NO.', 'AC NAME', 'CANDIDATE NAME', 'GENDER', 'AGE', 'CATEGORY', 'PARTY', 'SYMBOL', 'GENERAL', 'POSTAL', 'TOTAL', 'OVER VALID VOTES + NOTA', 'OVER TOTAL ELECTORS', 'TOTAL ELECTORS']
        legacy = expected[:4] + ['SEX'] + expected[5:12] + ['% VOTES POLLED', 'TOTAL ELECTORS']
        if header not in [expected, legacy]:
            raise ValueError('Detailed headers changed')
        elector_column = header.index('TOTAL ELECTORS')
        current = None
        for index, row in enumerate(rows, header_index + 1):
            row = [v.strip() if isinstance(v, str) else v for v in row]
            if not any(v is not None for v in row): continue
            if row[0] == 'Disclaimer' or str(row[0]).startswith('This report is based on Index Cards'): break
            if isinstance(row[0], str) and row[0].startswith('*') and all(v is None for v in row[1:]):
                notes.append(row[0])
                continue
            if normal(row[0]) == 'grandtotal': continue
            if normal(row[0]) == 'turnout':
                if current is None: raise ValueError('Total without constituency')
                current['detail_totals'] = [count(v) for v in row[9:12]]
                continue
            if not state_matches(row[0], state) or count(row[1]) is None:
                raise ValueError('Unrecognised geography at row ' + str(index))
            code = int(row[1])
            if code not in records:
                records[code] = dict(code=code, state_name=row[0], catalogue_state=state, name=row[2], constituency_name=row[2], number_of_seats=1, candidates=[], electors=count(row[elector_column]), issues=list(getattr(book, 'reader_notes', [])), source_locator=f'{book.active.title}, row {index}')
            current = records[code]
            if current['name'] != row[2] or current['electors'] != count(row[elector_column]):
                current['issues'].append('Repeated constituency name or elector total differs.')
            match = re.fullmatch(r'(\d+)\s+(.+)', str(row[3]))
            if not match: raise ValueError('Candidate serial/name format changed at row ' + str(index))
            candidate = dict(source_row=int(match[1]), workbook_row=index, candidate_name=match[2], party_at_election=str(row[7]), is_nota=str(row[7]).upper() == 'NOTA', general_votes=count(row[9]), postal_votes=count(row[10]), votes=count(row[11]), source_values=list(row))
            current['candidates'].append(candidate)
    finally: book.close()
    book = load_cells(summary)
    seen = set()
    try:
        for sheet in book:
            rows = [[v.strip() if isinstance(v, str) else v for v in r] for r in sheet.values]
            identity = re.fullmatch(r'(\d+)-(.+)', str(rows[1][3]))
            state_identity = re.fullmatch(r'([SU]\d+)-(.+)', str(rows[1][1]))
            legacy_summary = normal(rows[1][0]) == 'stateutcode' and re.fullmatch(r'[SU]\d+', str(rows[1][1])) is not None
            if legacy_summary:
                sheet_identity = re.fullmatch(r'([SU]\d+)-(\d+)', sheet.title)
                if not sheet_identity or sheet_identity[1] != rows[1][1]: raise ValueError('Summary sheet and state codes differ')
                identity = re.fullmatch(r'(\d+)-(.+)', sheet_identity[2] + '-' + str(rows[1][3]))
                state_identity = re.fullmatch(r'([SU]\d+)-(.+)', rows[1][1] + '-' + state)
            if not identity or not state_identity or not state_matches(state_identity[2], state):
                raise ValueError('Summary identity changed')
            summary_name = re.sub(r'-(?:GEN|SC|ST|BL)$', '', identity[2], flags=re.I)
            code = int(identity[1])
            if code in seen: raise ValueError('Duplicate summary constituency')
            seen.add(code)
            if code not in records:
                records[code] = dict(code=code, state_name=state_identity[2], catalogue_state=state, name=summary_name, constituency_name=summary_name, number_of_seats=1, candidates=[], electors=None, issues=['Detailed candidate rows are absent; check the official summary, including any uncontested result.'], source_locator='No detailed workbook rows')
            record = records[code]
            record['issues'].extend(getattr(book, 'reader_notes', []))
            record['state_code'] = state_identity[1]
            record['summary_locator'] = 'Summary sheet ' + sheet.title
            record['summary_source_rows'] = rows
            if normal(record['name']) != normal(summary_name):
                record['issues'].append('Detailed and summary constituency names differ; totals were not attached.')
                continue
            def field(label):
                if legacy_summary:
                    section, target = {'4. Total': ('electors', 'total'), '5. Total': ('voters', 'total'), '7. Total Valid Votes Polled': ('votes', 'totalvalidvotespolled')}[label]
                    current_section = None; matches = []
                    for r in rows:
                        if r[0] is not None: current_section = normal(r[0])
                        if current_section == section and normal(r[1]) == target: matches.append(r)
                    return count(matches[0][6]) if len(matches) == 1 else None
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
        term = r'detailed\s+results' if prefix == '10-' else r'constituency\s+data\s+summ'
        matches = [f for f in manifest['files'] if re.search(term, f['name'], re.I) and f['file'].endswith(('.xlsx', '.xls'))]
        if len(matches) != 1: raise ValueError('Expected one workbook for report ' + prefix)
        item = matches[0]; path = folder / item['file']
        if Path(item['file']).name != item['file'] or hashlib.sha256(path.read_bytes()).hexdigest() != item['sha256']: raise ValueError('Source integrity failed')
        return item, path
    detail, detail_path = source('10-')
    summary, summary_path = source('8-')
    records = extract(detail_path, summary_path, entry['state'])
    if not records: raise ValueError('No constituency records extracted')
    data = dict(kind='ac', year=entry['year'], source_url=entry['url'], source_file=detail['file'], source_sha256=detail['sha256'], extracted_at=datetime.now(timezone.utc).isoformat(), additional_sources=[summary], records=records)
    output = folder / 'extraction.json'
    if output.exists():
        previous = output.read_bytes(); backup = folder / ('extraction-' + hashlib.sha256(previous).hexdigest() + '.json')
        if not backup.exists(): backup.write_bytes(previous)
    temporary = folder / 'extraction.tmp'; temporary.write_text(json.dumps(data, indent=2, ensure_ascii=False), encoding='utf-8'); temporary.replace(output)
    print(entry['state'], len(records), 'tables;', sum(r['status'] != 'validated' for r in records), 'with notes', flush=True)


if __name__ == '__main__':
    parser=argparse.ArgumentParser();parser.add_argument('catalogue',type=Path);parser.add_argument('root',type=Path);parser.add_argument('--year',type=int,required=True);args=parser.parse_args()
    entries = [e for e in json.loads(args.catalogue.read_text(encoding='utf-8'))['entries'] if e['year'] == args.year]
    if not entries: parser.error('No catalogue editions for this year')
    failed = False
    for entry in entries:
        try: run(entry,args.root)
        except Exception as error:
            failed = True
            print(entry['state'] + ': extraction needs attention: ' + str(error), flush=True)
    if failed: raise SystemExit(1)
