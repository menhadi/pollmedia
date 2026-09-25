"""Propose Form 20 rows only when OCR cells and printed totals fully reconcile.

These rows remain unverified OCR evidence. They must not replace the source PDF or
be treated as certified results without checking the original page.
"""

import re


def _number(word):
    value = str(word.get('text', '')).strip()
    return int(value.replace(',', '')) if re.fullmatch(r'\d{1,3}(?:,\d{3})*|\d+', value) else None


def _center(word):
    return word['left'] + word['width'] / 2


def _good(word):
    return word.get('confidence', 0) >= 85


def _anchor(words, label, near_y=None, tolerance=35, min_x=None, max_x=None):
    matches = [w for w in words if _good(w) and re.sub(r'[^a-z]', '', w['text'].lower()) == label]
    if near_y is not None:
        matches = [w for w in matches if abs(w['top'] - near_y) <= tolerance]
    if min_x is not None:
        matches = [w for w in matches if _center(w) > min_x]
    if max_x is not None:
        matches = [w for w in matches if _center(w) < max_x]
    return max(matches, key=lambda w: w['confidence'], default=None)


def _clusters(words, left, right, start_y):
    """Find repeated numeric column centres without filling any missing cell."""
    numeric = sorted(((_center(w), w) for w in words if _good(w) and _number(w) is not None
                      and left < _center(w) < right and w['top'] >= start_y), key=lambda item: item[0])
    groups = []
    for x, word in numeric:
        if not groups or x - groups[-1][-1][0] > 38:
            groups.append([])
        groups[-1].append((x, word))
    return [sum(x for x, _ in group) / len(group) for group in groups if len(group) >= 2]


def _cell(words, x, y):
    candidates = [w for w in words if _good(w) and _number(w) is not None
                  and abs(_center(w) - x) <= 38 and abs(w['top'] - y) <= 12]
    return candidates[0] if len(candidates) == 1 else None


def propose_rows(page):
    """Return flagged proposals from a clean, explicit Form 20 OCR grid.

    Ambiguous headers, missing candidate labels or vote cells, duplicate cells,
    and arithmetic discrepancies yield no row. No value is inferred as zero.
    """
    words = page.get('words', [])
    if page.get('ocr_error') or page.get('quality') != 'unverified_ocr' or len(words) < 20:
        return []
    rejected = _anchor(words, 'rejected')
    if rejected is None:
        return []
    rejected_x = _center(rejected)
    valid = _anchor(words, 'valid', rejected['top'], tolerance=70, max_x=rejected_x)
    nota = _anchor(words, 'nota', rejected['top'], tolerance=70, min_x=rejected_x)
    if valid is None or nota is None:
        return []
    station = _anchor(words, 'station', rejected['top'], tolerance=70, max_x=_center(valid))
    total = _anchor(words, 'total', rejected['top'], tolerance=70, min_x=_center(nota))
    if station is None or total is None:
        return []
    anchors = [_center(w) for w in (station, valid, rejected, nota, total)]
    if not anchors[0] < anchors[1] < anchors[2] < anchors[3] < anchors[4]:
        return []
    header_y = rejected['top']
    candidate_x = _clusters(words, anchors[0] + 90, anchors[1] - 55, header_y + 45)
    if not 2 <= len(candidate_x) <= 20 or any(b - a < 65 for a, b in zip(candidate_x, candidate_x[1:])):
        return []
    station_x = _clusters(words, anchors[0] + 35, candidate_x[0] - 55, header_y + 45)
    if len(station_x) != 1:
        return []
    station_words = sorted((w for w in words if _good(w) and _number(w) is not None
                            and abs(_center(w) - station_x[0]) <= 38 and w['top'] >= header_y + 45),
                           key=lambda w: w['top'])
    if len(station_words) < 2:
        return []
    first_row_y = station_words[0]['top']
    names = [[] for _ in candidate_x]
    for word in words:
        if not _good(word) or not re.fullmatch(r'[A-Za-z][A-Za-z.\-]*', word['text']):
            continue
        if not header_y - 12 <= word['top'] < first_row_y - 12:
            continue
        index = min(range(len(candidate_x)), key=lambda i: abs(_center(word) - candidate_x[i]))
        if abs(_center(word) - candidate_x[index]) <= 60:
            names[index].append(word)
    labels = [' '.join(w['text'] for w in sorted(group, key=lambda w: (w['top'], w['left']))) for group in names]
    if any(len(group) < 2 or len(group) > 5 for group in names) or len(set(labels)) != len(labels):
        return []
    header_words = {'votes', 'cast', 'in', 'favour', 'favor', 'of', 'no', 'serial',
                    'polling', 'station', 'valid', 'total', 'rejected', 'nota', 'tendered'}
    if any(any(word['text'].lower().strip('.-') in header_words for word in group) for group in names):
        return []

    proposals = []
    seen_stations = set()
    for station_word in station_words:
        y = station_word['top']
        station_no = _number(station_word)
        if station_no is None or station_no == 0 or station_no in seen_stations:
            continue
        seen_stations.add(station_no)
        candidate_cells = [_cell(words, x, y) for x in candidate_x]
        total_cells = {key: _cell(words, x, y) for key, x in
                       [('valid_votes', anchors[1]), ('rejected_votes', anchors[2]),
                        ('nota', anchors[3]), ('total_votes', anchors[4])]}
        if any(cell is None for cell in candidate_cells) or any(cell is None for cell in total_cells.values()):
            continue
        votes = [_number(cell) for cell in candidate_cells]
        totals = {key: _number(cell) for key, cell in total_cells.items()}
        if sum(votes) != totals['valid_votes']:
            continue
        if totals['valid_votes'] + totals['rejected_votes'] + totals['nota'] != totals['total_votes']:
            continue
        evidence = [station_word, *candidate_cells, *total_cells.values()]
        proposals.append({'page': page['page'], 'source_sha256': page['source_sha256'],
                          'source_url': page['source_url'], 'polling_station': str(station_no),
                          'candidate_votes': [{'name': name, 'votes': vote} for name, vote in zip(labels, votes)],
                          **totals, 'ocr_word_boxes': evidence, 'quality': 'unverified_ocr',
                          'notes': ['OCR cells reconcile with printed totals, but names and values still require visual checking against the official PDF.']})
    return proposals
