"""Strict adapters for the pilot ECI report layouts. Layout changes fail for review."""
import hashlib
import json
import re
import sys
import zipfile
from pathlib import Path
import xml.etree.ElementTree as ET


def integer(value):
    text = str(value).strip()
    if not re.fullmatch(r"\d{1,9}", text):
        raise ValueError("Missing or invalid integer in election report.")
    return int(text)


def name(value):
    return " ".join(str(value or "").split())


def candidate(rank, candidate_name, party, general, postal, total):
    party = name(party)
    return dict(source_row=integer(rank), candidate_name="NOTA" if party == "NOTA" else name(candidate_name), party_at_election=party,
                is_nota=party == "NOTA", general_votes=integer(general), postal_votes=integer(postal), votes=integer(total))


def safe_zip(path):
    archive = zipfile.ZipFile(path)
    if sum(x.file_size for x in archive.infolist()) > 100_000_000:
        archive.close()
        raise ValueError("Expanded workbook exceeds the supported limit.")
    return archive


def extract(detail, summary, scope):
    year, code = scope['year'], scope['code']
    if scope['type'] == 'ac' and year == 2022 and code in [118, 127, 128, 129, 130]:
        import openpyxl
        with safe_zip(detail):
            pass
        workbook = openpyxl.load_workbook(detail, read_only=True, data_only=False)
        sheet = workbook['Worksheet']
        all_rows = []
        try:
            for row in sheet.values:
                all_rows.append(row)
                if len(all_rows) > 20000 or len(row) > 100:
                    raise ValueError("AC workbook exceeds the supported dimensions.")
        finally:
            workbook.close()
        if len(all_rows) < 4 or all_rows[3][:4] != ('STATE/UT NAME', 'AC NO.', 'AC NAME', 'CANDIDATE NAME'):
            raise ValueError("AC workbook layout changed.")
        rows, locators, electors = [], [], set()
        for index, row in enumerate(all_rows, 1):
            if row[1] != code:
                continue
            if row[0] != 'Uttar Pradesh' or name(row[2]).casefold() != scope['name'].casefold():
                raise ValueError("AC identity does not match the selected constituency.")
            rank = re.fullmatch(r'(\d+)\s+(.+)', name(row[3]))
            if not rank:
                raise ValueError("AC candidate rank is missing.")
            rows.append(candidate(rank[1], rank[2], row[7], row[9], row[10], row[11]))
            electors.add(integer(row[13]))
            locators.append(index)
        ns = {'s': 'http://schemas.openxmlformats.org/spreadsheetml/2006/main'}
        with safe_zip(summary) as archive:
            strings = [''.join(x.itertext()) for x in ET.fromstring(archive.read('xl/sharedStrings.xml')).findall('s:si', ns)]
            root = ET.fromstring(archive.read(f'xl/worksheets/sheet{code}.xml'))
            cells = {}
            for cell in root.findall('.//s:c', ns):
                value = cell.find('s:v', ns)
                if cell.find('s:f', ns) is not None:
                    raise ValueError("Summary contains formulas; a reviewed values-only report is required.")
                if value is not None:
                    cells[cell.get('r')] = strings[int(value.text)] if cell.get('t') == 's' else value.text
        category = 'SC' if code == 129 else 'GEN'
        if name(cells['D2']).casefold() != f"{code}-{scope['name']}-({category})".casefold():
            raise ValueError("Summary AC identity mismatch.")
        total_electors = integer(cells['F13'])
        if electors != {total_electors}:
            raise ValueError("Detailed and summary elector totals differ.")
        if sum(r['votes'] for r in rows if r['is_nota']) != integer(cells['F30']):
            raise ValueError("NOTA total differs from the summary.")
        result = dict(candidates=rows, electors=total_electors, votes_polled=integer(cells['F22'])+integer(cells['F25']), valid_candidate_votes=integer(cells['F28']), source_locator=f"10-Detailed Results, Worksheet rows {min(locators)}-{max(locators)}; Summary AC {code}, F13/F22/F25/F28")
    elif scope['type'] == 'pc' and code == 26 and year in [2019, 2024]:
        import pdfplumber
        page_index = 433 if year == 2024 else 458
        with pdfplumber.open(detail) as document:
            if len(document.pages) > 1500:
                raise ValueError("PDF exceeds the supported page limit.")
            page = document.pages[page_index]
            text = page.extract_text()
            match = re.search(r'Constituency:\s+26\s*\.\s*Pilibhit\s*\(\s*Total Electors\s+(\d+)\)', text)
            if not match:
                raise ValueError("PC report page or constituency identity changed.")
            total_electors = integer(match[1])
            if year == 2024:
                table = page.extract_tables()[0]
                if table[0][7] != 'Total Votes\nPolled In\nThe\nConstituency' or table[1][9:12] != ['General', 'Postal', 'Total']:
                    raise ValueError("PC 2024 table layout changed.")
                data = table[2:-1]
                rows = [candidate(r[0], r[1], r[5], r[9], r[10], r[11]) for r in data]
                totals = {(integer(r[7]), integer(r[8])) for r in data}
                if len(totals) != 1 or sum(r['votes'] for r in rows) != integer(table[-1][11]):
                    raise ValueError("PC table totals differ.")
                polled, valid = totals.pop()
            else:
                words = page.extract_words()
                serials = [w for w in words if w['x0'] < 60 and w['top'] > 125 and w['text'].isdigit()]
                total_word = next(w for w in words if w['text'] == 'TOTAL' and w['top'] > 125)
                def read(x1, x2, y1, y2):
                    return name(' '.join(w['text'] for w in words if x1 <= w['x0'] < x2 and y1 <= w['top'] < y2))
                rows = []
                for i, serial in enumerate(serials):
                    top = 125 if i == 0 else (serials[i-1]['top'] + serial['top']) / 2
                    bottom = total_word['top'] if i == len(serials)-1 else (serial['top'] + serials[i+1]['top']) / 2
                    rows.append(candidate(serial['text'], read(60,119,top,bottom), read(224,258,top,bottom), read(310,355,top,bottom), read(355,393,top,bottom), read(393,438,top,bottom)))
                if sum(r['votes'] for r in rows) != integer(read(393,438,total_word['top']-1,total_word['top']+12)):
                    raise ValueError("PC detailed total does not reconcile.")
                with pdfplumber.open(summary) as summary_document:
                    summary_text = summary_document.pages[772].extract_text()
                    if not re.search(r'CONSTITUENCY\s*:\s*PILIBHIT GEN 26', summary_text) or 'STATE/UT: S24' not in summary_text:
                        raise ValueError("PC summary identity mismatch.")
                    def value(pattern):
                        found = re.search(pattern, summary_text)
                        if not found:
                            raise ValueError("PC summary layout changed.")
                        return integer(found[1])
                    summary_electors = value(r'4\. TOTAL \d+ \d+ \d+ (\d+)')
                    if total_electors != summary_electors:
                        raise ValueError("Detailed and summary elector totals differ.")
                    polled = value(r'1\. TOTAL VOTES POLLED ON EVM (\d+)') + value(r'4\. POSTAL VOTES COUNTED (\d+)')
                    valid = value(r'7\. TOTAL VALID VOTES POLLED (\d+)')
        result = dict(candidates=rows, electors=total_electors, votes_polled=polled, valid_candidate_votes=valid, source_locator=f"Report 33, page {page_index+1}" + ('; totals: Report 32, page 773' if year == 2019 else ''))
    else:
        raise ValueError("This election edition does not have a verified report adapter yet.")
    ranks = sorted(r['source_row'] for r in result['candidates'])
    if ranks != list(range(1, len(ranks)+1)) or len(ranks) < 3:
        raise ValueError("Candidate ranks are incomplete or duplicated.")
    result.update(year=year, code=code, sha256=hashlib.sha256(detail.read_bytes()).hexdigest())
    if summary:
        result['totals_sha256'] = hashlib.sha256(summary.read_bytes()).hexdigest()
    return result


if __name__ == '__main__':
    try:
        result = extract(Path(sys.argv[1]), Path(sys.argv[2]) if sys.argv[2] else None, json.load(sys.stdin))
        sys.stdout.buffer.write(json.dumps(result, ensure_ascii=False).encode('utf-8'))
    except Exception as error:
        message = str(error) if isinstance(error, ValueError) else 'Report layout could not be parsed. Check edition, file pair and page layout.'
        sys.stdout.buffer.write(json.dumps({'error': message}).encode('utf-8'))
        sys.exit(1)
