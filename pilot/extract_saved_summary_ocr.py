"""Read a fixed-format ECI summary from preserved OCR words, with strict checks."""

import re


def normalized(name: str) -> str:
    return re.sub(r'[^a-z0-9]', '', re.sub(r'\s*\((?:SC|ST)\)\s*$', '', name, flags=re.I).casefold())


def row_value(words: list, y: float, left: float, right: float, numeric: str) -> int | float | None:
    matches = [word for word in words if abs(word[1] - y) <= 2.5 and left <= word[0] < right
               and word[5] >= 85 and re.fullmatch(numeric, word[4])]
    if len(matches) != 1:
        return None
    return float(matches[0][4]) if '.' in matches[0][4] else int(matches[0][4])


def parse_arunachal_2014(page: dict, record: dict) -> dict | None:
    """Accept only a page whose code/name, elector count and turnout percentage agree."""
    words = page['words']
    page_number = page['page']
    if (page_number != record.get('code', 0) + 12 or not 13 <= page_number <= 72
            or 'CONSTITUENCY DATA - SUMMARY' not in page['text'].upper()
            or record.get('status') != 'needs_review' or record.get('votes_polled') not in (None, 0)):
        return None
    identity = sorted((word for word in words if 67 <= word[1] <= 72 and 150 <= word[0] < 400),
                      key=lambda word: word[0])
    if not identity:
        return None
    heading = ' '.join(word[4] for word in identity)
    match = re.match(r'^(\d+)\s*-\s*(.+)$', heading)
    if not match or int(match[1]) != record['code'] or normalized(match[2]) != normalized(record['name']):
        return None
    # This edition prints its summary columns at the same positions on all 60 pages.
    # Read one isolated OCR number from each labelled total row; never use text order.
    required = ((278, 'II. ELECTORS'), (386, 'III. VOTERS'), (404, 'POLLING PERCENTAGE'),
                (578, '7.TOTAL VALID'))
    for y, label in required:
        if not any(abs(word[1] - y) < 5 and word[0] < 140 and label.split()[-1].split('.')[-1]
                   in word[4].upper() for word in words):
            # Row-position checks below also require the actual source numbers.
            if y not in (278, 386, 578):
                return None
    electors = row_value(words, 278, 500, 550, r'\d{3,7}')
    voters = row_value(words, 386, 500, 550, r'\d{3,7}')
    percentage = row_value(words, 404, 195, 250, r'\d{1,3}\.\d{2}')
    valid = row_value(words, 578, 500, 550, r'\d{3,7}')
    if (not all(value is not None for value in (electors, voters, percentage, valid))
            or record.get('electors') != electors or not 0 < valid <= voters <= electors
            or abs(100 * voters / electors - percentage) > 0.0051):
        return None
    return {'code': record['code'], 'name': record['name'], 'electors': electors,
            'votes_polled': voters, 'valid_candidate_votes': valid, 'summary_page': page_number,
            'ocr_page': page_number, 'ocr_method': 'preserved word coordinates'}


GUJARAT_HEADING = re.compile(r'Constituency\s+(\d+)\.\s*(.*?)\s+TOTAL ELECTORS\s*:\s*(\d+)', re.I)
GUJARAT_TURNOUT = re.compile(r'TURNOUT\s+TOTAL\s*:\s*(\d+)\s+(\d+)\s+(\d+)\s+(\d+\.\d+)', re.I)


def read_gujarat_2012(pages: list[dict], records: list[dict]) -> dict[int, dict]:
    """Read only isolated detailed-page turnout totals confirmed by electors and percentage."""
    if len(records) != 182 or len(pages) != 78 or [page['page'] for page in pages] != list(range(204, 282)):
        raise ValueError('Gujarat detailed-page coverage differs')
    text = '\n'.join(page['text'] for page in pages)
    offsets = []
    running = 0
    for page in pages:
        offsets.append((running, page['page']))
        running += len(page['text']) + 1
    headings = list(GUJARAT_HEADING.finditer(text))
    found = {}
    for index, heading in enumerate(headings):
        code = int(heading[1])
        if not 1 <= code <= 182 or code in found:
            continue
        record = records[code - 1]
        if (record.get('code') != code or record.get('status') != 'needs_review'
                or record.get('votes_polled') not in (None, 0)
                or normalized(heading[2]) != normalized(record.get('name') or '')
                or int(heading[3]) != record.get('electors')):
            continue
        block = text[heading.end():headings[index + 1].start() if index + 1 < len(headings) else len(text)]
        matches = list(GUJARAT_TURNOUT.finditer(block))
        if len(matches) != 1:
            continue
        general, postal, voters = (int(matches[0][part]) for part in range(1, 4))
        percentage = float(matches[0][4])
        electors = int(heading[3])
        if (not 0 < voters <= electors or general + postal != voters
                or abs(100 * voters / electors - percentage) > 0.011):
            continue
        turnout_at = heading.end() + matches[0].start()
        page_number = max(number for offset, number in offsets if offset <= turnout_at)
        found[code] = {'code': code, 'name': record['name'], 'electors': electors,
                       'votes_polled': voters, 'detail_page': page_number,
                       'ocr_page': page_number, 'general_votes': general,
                       'postal_votes': postal, 'source_turnout_percent': percentage}
    return found
