"""Read the verified UP 2022 ECI workbook pair once, keeping per-seat failures."""
import json
import re
import sys
import xml.etree.ElementTree as ET
from pathlib import Path
import openpyxl
from extract_election_import import candidate, integer, name, safe_zip


def extract_state(detail, summary):
    with safe_zip(detail):
        pass
    workbook = openpyxl.load_workbook(detail, read_only=True, data_only=False)
    groups = {}
    try:
        sheet = workbook['Worksheet']
        for index, row in enumerate(sheet.values, 1):
            if index > 20000 or len(row) > 100:
                raise ValueError('Detailed workbook exceeds supported dimensions.')
            if index == 4 and row[:4] != ('STATE/UT NAME', 'AC NO.', 'AC NAME', 'CANDIDATE NAME'):
                raise ValueError('Detailed workbook header changed.')
            if index <= 4 or all(v is None for v in row):
                continue
            if row[1] is None and (row[0] in ['TURN OUT', 'GRAND TOTAL:', 'Disclaimer'] or str(row[0]).startswith('This report is based on Index Cards data made available')):
                continue
            if row[0] != 'Uttar Pradesh' or type(row[1]) is not int or not 1 <= row[1] <= 403:
                raise ValueError('Unexpected state or constituency code in detailed workbook.')
            groups.setdefault(row[1], []).append((index, row))
    finally:
        workbook.close()
    if set(groups) != set(range(1, 404)):
        raise ValueError('The report must contain all 403 UP constituency codes.')
    ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
    results = []
    with safe_zip(summary) as archive:
        strings = [''.join(x.itertext()) for x in ET.fromstring(archive.read('xl/sharedStrings.xml')).findall('s:si', ns)]
        for code, entries in sorted(groups.items()):
            seat_name = name(entries[0][1][2])
            record = dict(code=code, name=seat_name)
            try:
                cells = {}
                root = ET.fromstring(archive.read(f'xl/worksheets/sheet{code}.xml'))
                for cell in root.findall('.//s:c', ns):
                    if cell.find('s:f', ns) is not None:
                        raise ValueError('Summary contains formulas.')
                    value = cell.find('s:v', ns)
                    if value is not None:
                        cells[cell.get('r')] = strings[int(value.text)] if cell.get('t') == 's' else value.text
                identity = re.fullmatch(r'(\d+)-(.+)-\((GEN|SC|ST)\)', name(cells.get('D2')))
                if not identity or int(identity[1]) != code or name(identity[2]).casefold() != seat_name.casefold():
                    raise ValueError('Detailed and summary constituency identities differ.')
                candidates, electors = [], set()
                for index, row in entries:
                    if name(row[2]) != seat_name:
                        raise ValueError('Conflicting constituency names.')
                    rank = re.fullmatch(r'(\d+)\s+(.+)', name(row[3]))
                    if not rank:
                        raise ValueError('Missing candidate rank.')
                    candidates.append(candidate(rank[1], rank[2], row[7], row[9], row[10], row[11]))
                    electors.add(integer(row[13]))
                total_electors = integer(cells['F13'])
                if electors != {total_electors}:
                    raise ValueError('Elector totals differ between reports.')
                if sum(r['votes'] for r in candidates if r['is_nota']) != integer(cells['F30']):
                    raise ValueError('NOTA total differs between reports.')
                record['payload'] = dict(year=2022,code=code,name=seat_name,category=identity[3],candidates=candidates,electors=total_electors,votes_polled=integer(cells['F22'])+integer(cells['F25']),valid_candidate_votes=integer(cells['F28']),source_locator=f'Detailed Worksheet rows {entries[0][0]}-{entries[-1][0]}; Summary AC {code}, F13/F22/F25/F28/F30')
            except (ValueError, KeyError, IndexError, ET.ParseError) as error:
                record['error'] = str(error) if isinstance(error, ValueError) else 'Summary cells or layout missing.'
            results.append(record)
    return results


if __name__ == '__main__':
    try:
        sys.stdout.buffer.write(json.dumps(extract_state(Path(sys.argv[1]), Path(sys.argv[2])), ensure_ascii=False).encode('utf-8'))
    except Exception as error:
        sys.stdout.buffer.write(json.dumps({'error': str(error) if isinstance(error, ValueError) else 'Workbook pair could not be parsed.'}).encode('utf-8'))
        sys.exit(1)
