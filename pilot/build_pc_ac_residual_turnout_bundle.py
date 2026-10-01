"""Recover only source-printed turnout from residual AC gaps after the first import."""

import copy
import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from audit_pc_ac_zero_values import IMPORTED_PACKAGES, PROPOSED_PACKAGES, correction_index, effective_body
from build_pc_ac_zero_turnout_bundle import import_script
from extract_saved_summary_ocr import GUJARAT_HEADING, normalized
from preserve_archive_json import package


NAME = 'pollmedia-ac-residual-turnout-corrections-20261001-v4'
UP1951 = '402db61ff727c908b4ac3170'
GUJARAT2012 = '503135d3e838d38c93d3bce7'
SYMBOLS = {
    '775e12dc77eb9f634ba9a490': {8, 22, 43, 55, 75, 77, 79},  # Jharkhand 2014
    '7a8ef9c9247cb626f6a03998': {288},  # Andhra Pradesh 2014
    'c590e168b33b5fb8f213d092': {193, 228, 229, 230, 231, 232, 233},  # Maharashtra 2014
    'cccb0490a067808ecaa71a36': {64, 80},  # Chhattisgarh 2018
}
UP_CODES = {53, 105, 143, 146, 207, 210, 341}
GUJARAT_OCR_CODES = {4, 38, 42, 52, 78, 79, 114, 115}
# Checked against the archived official PDF at the stated heading and turnout
# pages. The saved OCR misses or garbles one or more numeric columns in many
# of these rows, so preserve the printed components too.
# Columns: electors, general votes, postal votes, total votes, percentage,
# heading page, turnout page.
GUJARAT_PDF_ROWS = {
    4: (191018, 136635, 741, 137376, 71.92, 205, 205),
    15: (228271, 162722, 847, 163569, 71.66, 209, 209),
    20: (177837, 125419, 1328, 126747, 71.27, 211, 212),
    31: (216158, 162046, 2208, 164254, 75.99, 216, 216),
    32: (200799, 151933, 1762, 153695, 76.54, 216, 217),
    38: (187268, 142036, 1061, 143097, 76.41, 219, 219),
    40: (207335, 152824, 948, 153772, 74.17, 219, 220),
    42: (273427, 192778, 2210, 194988, 71.31, 220, 221),
    43: (230317, 158412, 1067, 159479, 69.24, 221, 221),
    44: (223385, 149716, 1377, 151093, 67.64, 221, 222),
    52: (182445, 123581, 817, 124398, 68.18, 225, 226),
    65: (219462, 159640, 1533, 161173, 73.44, 231, 231),
    66: (196679, 149246, 739, 149985, 76.26, 231, 232),
    68: (210217, 144382, 655, 145037, 68.99, 232, 233),
    78: (182733, 120091, 1886, 121977, 66.75, 238, 238),
    79: (184138, 119934, 1145, 121079, 65.75, 238, 239),
    83: (212580, 144614, 1343, 145957, 68.66, 240, 241),
    91: (182589, 140234, 864, 141098, 77.28, 244, 244),
    92: (184653, 128373, 1349, 129722, 70.25, 244, 245),
    99: (181028, 120513, 1093, 121606, 67.18, 247, 248),
    114: (177138, 137441, 873, 138314, 78.08, 254, 254),
    115: (201690, 151615, 1016, 152631, 75.68, 254, 255),
    120: (242550, 180140, 2127, 182267, 75.15, 256, 256),
    128: (219593, 167680, 1160, 168840, 76.89, 259, 259),
    129: (181333, 119381, 1570, 120951, 66.70, 259, 260),
    137: (213248, 146238, 1549, 147787, 69.30, 262, 262),
    140: (185775, 143164, 748, 143912, 77.47, 263, 263),
    147: (179871, 140913, 458, 141371, 78.60, 266, 266),
    148: (202787, 156241, 2346, 158587, 78.20, 266, 267),
    157: (209348, 165969, 1279, 167248, 79.89, 270, 270),
    173: (144400, 99276, 1426, 100702, 69.74, 277, 277),
    176: (251458, 188467, 2510, 190977, 75.95, 278, 278),
    177: (250841, 201420, 2489, 203909, 81.29, 278, 279),
}
GUJARAT_CODES = GUJARAT_OCR_CODES | set(GUJARAT_PDF_ROWS)
HEADING = re.compile(r'(?P<name>[^\n]+)\n(?P<code>\d+)\.\s*\nConstituency\s*\nTOTAL ELECTORS\s*:\s*(?P<electors>\d+)', re.I)
SYMBOL_TOTAL = re.compile(r'(?P<postal>\d+)\s+(?P<total>\d+)\s+TOTAL:\s*(?P<general>\d+)\s+TURNOUT\s+(?P<percent>\d+(?:\.\d+)?)', re.I)
GUJARAT_TOTAL = re.compile(r'TURNOUT\s+TOTAL\s*:\s*(?P<general>\d+)\s+(?P<postal>\d+)\s+(?P<total>\d+)\s+(?P<percent>\d{1,3}[.,]\d{2})', re.I)


def sha256(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def file_sha256(path: Path) -> str:
    with path.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest()


def verified_source(folder: Path, data: dict) -> Path:
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if manifest.get('url') != data['source_url']:
        raise ValueError('Official source URL differs: ' + folder.name)
    matches = [item for item in manifest['files'] if item['file'] == data['source_file']]
    path = folder / data['source_file']
    if (len(matches) != 1 or path.resolve().parent != folder.resolve() or path.is_symlink()
            or matches[0]['sha256'] != data['source_sha256']
            or file_sha256(path) != data['source_sha256']):
        raise ValueError('Official source checksum differs: ' + folder.name)
    return path


def read_up1951(path: Path, records: list[dict]) -> dict[int, dict]:
    found = {}
    with fitz.open(path) as doc:
        for record in records:
            code = record['code']
            page = record.get('summary_page')
            if (code not in UP_CODES or not isinstance(page, int) or not 1 <= page <= len(doc)
                    or record.get('votes_polled') not in (None, 0)):
                continue
            text = doc[page - 1].get_text()
            header = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text)
            electors = re.search(r'ELECTORS\s+II\.\s+TOTAL\s+1\.\s+(\d+)', text)
            voted = re.search(r'ELECTORS WHO VOTED\s+III\.\s+TOTAL\s+1\.\s+(\d+)', text)
            votes = re.search(r'VOTES\s+IV\.\s+POLLED\s+1\.\s+VALID\s+2\.\s+(\d+)\s+(\d+)', text)
            if not all((header, electors, voted, votes)):
                continue
            source_name = normalized(header[2])
            source_electors, source_voted, polled, valid = (int(electors[1]), int(voted[1]),
                                                            int(votes[1]), int(votes[2]))
            candidates = record.get('candidates') or []
            if (int(header[1]) != code or len(source_name) < 20
                    or not normalized(record['name']).startswith(source_name)
                    or record.get('electors') not in (None, 0, source_electors)
                    or not 0 < valid <= polled == source_voted <= source_electors
                    or not candidates or any(type(c.get('votes')) is not int for c in candidates)
                    or sum(c['votes'] for c in candidates) != valid):
                continue
            found[code] = {'electors': source_electors, 'votes_polled': polled,
                           'valid_candidate_votes': valid, 'source_page': page,
                           'method': 'official constituency summary'}
    if set(found) != UP_CODES:
        raise ValueError('UP 1951 summary source checks did not recover all seven seats')
    return found


def read_symbol_detail(path: Path, records: list[dict], codes: set[int]) -> dict[int, dict]:
    pages, offsets, length = [], [], 0
    with fitz.open(path) as doc:
        for index, page in enumerate(doc):
            text = page.get_text()
            if 'DETAILED RESULTS' in text:
                pages.append(text)
                offsets.append((length, index + 1))
                length += len(text) + 1
    text = '\n'.join(pages)
    headings = list(HEADING.finditer(text))
    by_code = {record['code']: record for record in records if record['code'] in codes}
    candidates = {code: [] for code in codes}
    for index, heading in enumerate(headings):
        code = int(heading['code'])
        record = by_code.get(code)
        if record is None or normalized(heading['name']) != normalized(record['name']):
            continue
        electors = int(heading['electors'])
        if record.get('electors') != electors or record.get('votes_polled') not in (None, 0):
            continue
        block = text[heading.end():headings[index + 1].start() if index + 1 < len(headings) else len(text)]
        for total in SYMBOL_TOTAL.finditer(block):
            general, postal, voters = (int(total[key]) for key in ('general', 'postal', 'total'))
            percent = float(total['percent'])
            if (not 0 < voters <= electors or general + postal != voters
                    or abs(100 * voters / electors - percent) > 0.0051
                    or (record.get('detail_totals') or {}).get('votes') != voters):
                continue
            total_at = heading.end() + total.start()
            page = max(page for offset, page in offsets if offset <= total_at)
            candidates[code].append({'electors': electors, 'votes_polled': voters,
                                     'general_votes': general, 'postal_votes': postal,
                                     'source_turnout_percent': percent, 'source_page': page,
                                     'method': 'official detailed turnout row'})
    found = {code: values[0] for code, values in candidates.items() if len(values) == 1}
    if set(found) != codes:
        raise ValueError('Symbol-table turnout source checks differ: ' + str(sorted(codes - set(found))))
    return found


def read_gujarat2012(pages: list[dict], records: list[dict]) -> dict[int, dict]:
    if len(records) != 182 or [page['page'] for page in pages] != list(range(204, 282)):
        raise ValueError('Gujarat OCR page coverage differs')
    text = '\n'.join(page['text'] for page in pages)
    offsets, length = [], 0
    for page in pages:
        offsets.append((length, page['page']))
        length += len(page['text']) + 1
    headings = list(GUJARAT_HEADING.finditer(text))
    found = {}
    for index, heading in enumerate(headings):
        code = int(heading[1])
        if code not in GUJARAT_OCR_CODES:
            continue
        record = records[code - 1]
        electors = int(heading[3])
        if (record['code'] != code or normalized(heading[2]) != normalized(record['name'])
                or record.get('electors') != electors or record.get('votes_polled') not in (None, 0)):
            continue
        block = text[heading.end():headings[index + 1].start() if index + 1 < len(headings) else len(text)]
        eligible = []
        for total in GUJARAT_TOTAL.finditer(block):
            general, postal, voters = (int(total[key]) for key in ('general', 'postal', 'total'))
            percent = float(total['percent'].replace(',', '.'))
            candidates = record.get('candidates') or []
            candidate_sum = sum(c['votes'] for c in candidates if type(c.get('votes')) is int)
            if (not 0 < candidate_sum <= voters <= electors
                    or abs(general + postal - voters) > 3
                    or abs(100 * voters / electors - percent) > 0.0051):
                continue
            page_at = heading.end() + total.start()
            page = max(number for offset, number in offsets if offset <= page_at)
            eligible.append({'electors': electors, 'votes_polled': voters,
                             'general_votes_ocr': general, 'postal_votes_ocr': postal,
                             'ocr_component_difference': general + postal - voters,
                             'source_turnout_percent': percent, 'source_page': page,
                             'method': 'official detailed turnout OCR with elector and percentage match'})
        if len(eligible) == 1:
            found[code] = eligible[0]
    # The saved OCR reads "79," rather than "79." in this one heading. Use
    # its preserved word coordinates to keep the turnout row tied to that seat.
    code = 79
    if code in GUJARAT_OCR_CODES and code not in found:
        record = records[code - 1]
        events = []
        for page in pages:
            for word in page['words']:
                if word[0] > 80 or word[4].lower() not in ('constituency', 'turnout'):
                    continue
                line = sorted((w for w in page['words'] if abs(w[1] - word[1]) < 3.5), key=lambda w: w[0])
                if word[4].lower() == 'constituency':
                    codes = [w for w in line if 105 < w[0] < 136 and re.fullmatch(r'\d+[.,]', w[4])]
                    if len(codes) != 1:
                        continue
                    name = ' '.join(w[4] for w in line if 135 <= w[0] < 350)
                    electors = [w for w in line if 483 < w[0] < 555 and re.fullmatch(r'\d+', w[4])]
                    events.append((page['page'], word[1], 'heading', int(re.search(r'\d+', codes[0][4])[0]),
                                   name, int(electors[0][4]) if len(electors) == 1 else None))
                else:
                    columns = [[w for w in line if left <= w[0] < right] for left, right in
                               ((380, 430), (430, 475), (475, 530), (530, 585))]
                    events.append((page['page'], word[1], 'turnout', columns))
        events.sort(key=lambda item: (item[0], item[1]))
        headings = [(index, event) for index, event in enumerate(events) if event[2] == 'heading']
        eligible = []
        for position, (index, heading) in enumerate(headings):
            if (heading[3] != code or heading[0] != record.get('detail_page')
                    or normalized(heading[4]) != normalized(record['name'])
                    or heading[5] != record.get('electors')):
                continue
            end = headings[position + 1][0] if position + 1 < len(headings) else len(events)
            for event in events[index + 1:end]:
                if event[2] != 'turnout':
                    continue
                columns = event[3]
                if (any(len(column) != 1 or column[0][5] < 85 for column in columns)
                        or any(not re.fullmatch(r'\d+', column[0][4]) for column in columns[:3])
                        or not re.fullmatch(r'\d{1,3}\.\d{2}', columns[3][0][4])):
                    continue
                general, postal, voters = (int(column[0][4]) for column in columns[:3])
                percent = float(columns[3][0][4])
                electors = heading[5]
                if (not 0 < voters <= electors or general + postal != voters
                        or abs(100 * voters / electors - percent) > 0.0051):
                    continue
                eligible.append({'electors': electors, 'votes_polled': voters,
                                 'general_votes_ocr': general, 'postal_votes_ocr': postal,
                                 'ocr_component_difference': 0, 'source_turnout_percent': percent,
                                 'source_page': event[0], 'method': 'official detailed turnout OCR coordinates'})
        if len(eligible) == 1:
            found[code] = eligible[0]
    if set(found) != GUJARAT_OCR_CODES:
        raise ValueError('Gujarat OCR turnout checks differ: ' + str(sorted(GUJARAT_OCR_CODES - set(found))))
    # Position of each scanned constituency and TURNOUT row survives even
    # where the OCR text does not. Bind each visual PDF transcription to that
    # unique source-page pair and reject changed page coverage or identity.
    events = []
    for page in pages:
        for word in page['words']:
            if word[0] > 80 or word[4].lower() not in ('constituency', 'turnout'):
                continue
            line = sorted((w for w in page['words'] if abs(w[1] - word[1]) < 3.5), key=lambda w: w[0])
            if word[4].lower() == 'constituency':
                codes = [w for w in line if 105 < w[0] < 136 and re.fullmatch(r'\d+[.,]', w[4])]
                if len(codes) == 1:
                    events.append((page['page'], word[1], 'heading', int(re.search(r'\d+', codes[0][4])[0])))
            else:
                events.append((page['page'], word[1], 'turnout', None))
    events.sort(key=lambda item: (item[0], item[1]))
    positions = [(i, event) for i, event in enumerate(events) if event[2] == 'heading']
    if len(positions) != 182 or sum(event[2] == 'turnout' for event in events) != 182:
        raise ValueError('Gujarat source heading/turnout geometry differs')
    for code, row in GUJARAT_PDF_ROWS.items():
        electors, general, postal, voters, percent, heading_page, turnout_page = row
        matches = [(position, index, heading) for position, (index, heading) in enumerate(positions)
                   if heading[3] == code and heading[0] == heading_page]
        if len(matches) != 1:
            raise ValueError(f'Gujarat {code} source heading is not unique')
        position, index, heading = matches[0]
        end = positions[position + 1][0] if position + 1 < len(positions) else len(events)
        turnout_rows = [event for event in events[index + 1:end] if event[2] == 'turnout']
        record = records[code - 1]
        candidate_sum = sum(c['votes'] for c in record.get('candidates', []) if type(c.get('votes')) is int)
        ocr_total = found.get(code)
        if ocr_total and (ocr_total['electors'] != electors
                          or abs(ocr_total['votes_polled'] - voters) > 3):
            raise ValueError(f'Gujarat {code} visual transcription conflicts with saved OCR total')
        if (len(turnout_rows) != 1 or turnout_rows[0][0] != turnout_page
                or record['code'] != code or record.get('detail_page') != heading_page
                or record.get('votes_polled') not in (None, 0)
                or (record.get('electors') not in (None, 0, electors)
                    and not (code == 99 and record.get('electors') == 3))
                or not 0 < candidate_sum <= voters <= electors
                or general + postal != voters
                or abs(100 * voters / electors - percent) > 0.0051):
            raise ValueError(f'Gujarat {code} printed source values do not reconcile')
        found[code] = {'electors': electors, 'votes_polled': voters,
                       'general_votes': general, 'postal_votes': postal,
                       'source_turnout_percent': percent, 'source_page': turnout_page,
                       'source_heading_page': heading_page,
                       'method': 'visual transcription of official scanned turnout row; OCR geometry verified'}
        if ocr_total and ocr_total['votes_polled'] != voters:
            found[code]['saved_ocr_votes_polled'] = ocr_total['votes_polled']
    if set(found) != GUJARAT_CODES:
        raise ValueError('Gujarat OCR turnout checks differ: ' + str(sorted(GUJARAT_CODES - set(found))))
    return found


def build(root: Path) -> dict:
    exports = root / 'exports'
    bundle = exports / (NAME + '.zip')
    if bundle.exists() or bundle.with_suffix('.sha256').exists():
        raise FileExistsError(bundle)
    revisions = correction_index(root, IMPORTED_PACKAGES + PROPOSED_PACKAGES)
    edition_codes = {UP1951: UP_CODES, GUJARAT2012: GUJARAT_CODES, **SYMBOLS}
    details = []
    with tempfile.TemporaryDirectory(prefix='ac-residual-turnout-', dir=exports) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        for edition, codes in edition_codes.items():
            folder = root / 'application/storage/app/private/election-archive' / edition
            old_body = effective_body(folder / 'extraction.json', revisions.get(edition, []))
            data = json.loads(old_body)
            source = verified_source(folder, data)
            if data.get('kind') != 'ac' or len({r['code'] for r in data['records']}) != len(data['records']):
                raise ValueError('Assembly source identity or code uniqueness differs')
            if edition == UP1951:
                if data['year'] != 1951: raise ValueError('UP year differs')
                totals = read_up1951(source, data['records'])
            elif edition == GUJARAT2012:
                if data['year'] != 2012: raise ValueError('Gujarat year differs')
                ocr = folder / data['ocr_file']
                if ocr.is_symlink() or file_sha256(ocr) != data['ocr_sha256']:
                    raise ValueError('Gujarat saved OCR checksum differs')
                saved = json.loads(ocr.read_text(encoding='utf-8'))
                if saved['source_sha256'] != data['source_sha256']:
                    raise ValueError('Gujarat OCR source identity differs')
                totals = read_gujarat2012(saved['pages'], data['records'])
            else:
                if data['year'] not in (2014, 2018): raise ValueError('Symbol-table year differs')
                totals = read_symbol_detail(source, data['records'], codes)
            revised = copy.deepcopy(data)
            for record in revised['records']:
                source_total = totals.get(record['code'])
                if source_total is None:
                    continue
                record['original_extraction_warning'] = record.get('error') or ''
                record['error'] = ('Official source prints the constituency turnout total; '
                                   'previous candidate/source warnings remain available for review.')
                record['source_warning_code'] = 'official_turnout_from_residual_source'
                record['votes_polled'] = source_total['votes_polled']
                if (record.get('electors') in (None, 0)
                        or (edition == GUJARAT2012 and record['code'] == 99
                            and record.get('electors') == 3)):
                    record['electors'] = source_total['electors']
                record['turnout_totals'] = source_total
                record['turnout_source_page'] = source_total['source_page']
                record['turnout_source_file'] = data['source_file']
                record['turnout_source_sha256'] = data['source_sha256']
                if edition == GUJARAT2012:
                    record['turnout_ocr_file'] = data['ocr_file']
                    record['turnout_ocr_sha256'] = data['ocr_sha256']
            for before, after in zip(data['records'], revised['records']):
                if (before['code'] != after['code'] or before['name'] != after['name']
                        or before.get('candidates') != after.get('candidates')
                        or before.get('status') != after.get('status')
                        or before.get('valid_candidate_votes') != after.get('valid_candidate_votes')
                        or (before.get('electors') not in (None, 0) and before['electors'] != after['electors']
                            and not (edition == GUJARAT2012 and before['code'] == 99
                                     and before['electors'] == 3 and after['electors'] == 181028))
                        or (before.get('votes_polled') not in (None, 0) and before['votes_polled'] != after['votes_polled'])):
                    raise ValueError('Existing election candidate or nonblank total changed')
            new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
            old_sha = sha256(old_body)
            snapshot = f'election-archive/{edition}/extraction-{old_sha}.json'
            revision = f'election-archive/{edition}/extraction.json'
            for relative, body in ((snapshot, old_body), (revision, new_body)):
                path = staged / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(body)
            details.append({'edition': edition, 'year': data['year'], 'source_url': data['source_url'],
                            'source_sha256': data['source_sha256'], 'previous_sha256': old_sha,
                            'new_sha256': sha256(new_body), 'codes': sorted(totals),
                            'totals': {str(code): totals[code] for code in sorted(totals)}})
        inner = []
        for prefix in ('snapshot', 'correction'):
            for detail in details:
                edition = detail['edition']
                prior = f'election-archive/{edition}/extraction-{detail["previous_sha256"]}.json'
                relative = prior if prefix == 'snapshot' else f'election-archive/{edition}/extraction.json'
                output = packages / f'{prefix}-{edition}.zip'
                bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
                package(staged, output, 'election-archive', bucket, 8, [relative],
                        detail['previous_sha256'] if prefix == 'correction' else None,
                        prior if prefix == 'correction' else None)
                inner.append(output)
        partial = bundle.with_suffix('.zip.partial')
        if partial.exists(): raise FileExistsError(partial)
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for item in inner: zipped.write(item, item.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{file_sha256(item)}  {item.name}\n' for item in inner))
            zipped.writestr('ARCHIVES', ''.join(d['edition'] + '\n' for d in details))
            zipped.writestr('AUDIT.json', json.dumps({'scope': 'Only printed AC residual turnout; no candidates changed',
                                                    'editions': details}, ensure_ascii=False, indent=2))
            zipped.writestr('IMPORT.sh', import_script([d['edition'] for d in details]))
        partial.replace(bundle)
    digest = file_sha256(bundle)
    bundle.with_suffix('.sha256').write_bytes((digest + '  ' + bundle.name + '\n').encode('ascii'))
    return {'bundle': str(bundle), 'sha256': digest, 'editions': len(details),
            'corrected_rows': sum(len(d['codes']) for d in details)}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
