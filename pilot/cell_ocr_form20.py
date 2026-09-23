"""Read a ruled Form 20 page cell by cell; keep only reconciled OCR proposals.

This is a layout-specific research adapter. Output is unverified evidence for
visual review, never a replacement for the official PDF or certified result.
"""

import argparse
import hashlib
import json
from pathlib import Path
import re

import cv2
import fitz
import numpy as np
import pytesseract


def _peaks(values, minimum):
    groups = []
    for i, value in enumerate(values):
        if value < minimum:
            continue
        if not groups or i > groups[-1][-1] + 3:
            groups.append([])
        groups[-1].append(i)
    return [round(sum(group) / len(group)) for group in groups]


def grid_lines(gray):
    """Return strong ruled-table boundaries or fail closed."""
    height, width = gray.shape
    binary = cv2.threshold(gray, 0, 255, cv2.THRESH_BINARY_INV | cv2.THRESH_OTSU)[1]
    horizontal = cv2.morphologyEx(binary, cv2.MORPH_OPEN,
                                 cv2.getStructuringElement(cv2.MORPH_RECT, (max(60, width // 22), 1)))
    vertical = cv2.morphologyEx(binary, cv2.MORPH_OPEN,
                               cv2.getStructuringElement(cv2.MORPH_RECT, (1, max(35, height // 27))))
    xs = _peaks(np.count_nonzero(vertical, axis=0), max(150, round(height * .30)))
    ys = _peaks(np.count_nonzero(horizontal, axis=1), max(250, round(width * .35)))
    if len(xs) < 10 or len(ys) < 6 or any(b - a < 20 for a, b in zip(xs, xs[1:])):
        return None
    gaps = [b - a for a, b in zip(ys, ys[1:])]
    row_gap = sorted(gaps)[len(gaps) // 2]
    header = next((i for i, gap in enumerate(gaps) if gap > max(38, 1.7 * row_gap)), None)
    if header is None or len(ys) - header < 5:
        return None
    rows = [(a, b) for a, b in zip(ys[header + 1:-1], ys[header + 2:])
            if 16 <= b - a <= 65]
    return (xs, ys[max(0, header - 1)], ys[header + 1], rows) if len(rows) >= 3 else None


def _crop(gray, x1, y1, x2, y2, scale=2):
    cell = gray[y1 + 3:y2 - 2, x1 + 3:x2 - 3]
    if min(cell.shape) < 8:
        return None
    return cv2.resize(cell, None, fx=scale, fy=scale, interpolation=cv2.INTER_CUBIC)


def _header(gray, x1, x2, top, bottom):
    crop = _crop(gray, x1, top, x2, bottom)
    if crop is None:
        return ''
    data = pytesseract.image_to_data(crop, config='--psm 6', output_type=pytesseract.Output.DICT)
    return ' '.join(token for token in data['text'] if token.strip()).strip()


def _header_layout(gray, xs, top, bottom):
    # This adapter supports the common two-leading-column layout only.
    split = top + round((bottom - top) / 3)
    labels = [_header(gray, a, b, split, bottom) for a, b in zip(xs, xs[1:])]
    normal = [re.sub(r'[^a-z]', '', label.lower()) for label in labels]
    valid = next((i for i, text in enumerate(normal) if text.startswith('validvote')), None)
    if valid is None or not 4 <= valid <= 22 or valid + 4 >= len(labels):
        return None
    if not (normal[valid + 1].startswith('rejectedvote')
            and 'nota' in normal[valid + 2] and normal[valid + 3] == 'total'
            and normal[valid + 4].startswith('tenderedvote')):
        return None
    leading = _header(gray, xs[0], xs[2], top, bottom).lower()
    if 'polling' not in leading or 'station' not in leading:
        return None
    names = [' '.join(re.findall(r'[A-Za-z]+', label)) for label in labels[2:valid]]
    if any(len(name.split()) < 2 or len(name.split()) > 5 for name in names):
        return None
    if len(set(name.lower() for name in names)) != len(names):
        return None
    return valid, names


def _read_cells(gray, xs, rows, columns, scale=2):
    """Batch isolated cells in small montages, keeping each crop's slot."""
    cells = [(r, c, _crop(gray, xs[c], y1, xs[c + 1], y2, scale))
             for r, (y1, y2) in enumerate(rows) for c in columns]
    values = {}
    for start in range(0, len(cells), 50):
        batch = cells[start:start + 50]
        slot_height = max(80, max((crop.shape[0] for _, _, crop in batch if crop is not None), default=0) + 20)
        width = max((crop.shape[1] for _, _, crop in batch if crop is not None), default=0) + 20
        if width < 30:
            continue
        montage = np.full((slot_height * len(batch), width), 255, dtype=np.uint8)
        for index, (_, _, crop) in enumerate(batch):
            if crop is None or crop.shape[0] > slot_height - 16:
                continue
            h, w = crop.shape
            montage[index * slot_height + 8:index * slot_height + 8 + h, 10:10 + w] = crop
        data = pytesseract.image_to_data(montage,
                                        config='--psm 6 -c tessedit_char_whitelist=0123456789',
                                        output_type=pytesseract.Output.DICT)
        tokens = [[] for _ in batch]
        for i, text in enumerate(data['text']):
            if not text.strip():
                continue
            index = (data['top'][i] + data['height'][i] // 2) // slot_height
            if 0 <= index < len(batch):
                tokens[index].append(text.strip())
        for (r, c, _), pieces in zip(batch, tokens):
            if len(pieces) == 1 and re.fullmatch(r'\d+', pieces[0]):
                values[r, c] = int(pieces[0])
    return values


def reconciled_rows(values, rows, valid, names, page, source_sha256, source_url, xs):
    proposals = []
    columns = list(range(1, valid + 5))
    seen_stations = set()
    for r, (top, bottom) in enumerate(rows):
        if any((r, c) not in values for c in columns):
            continue
        station = values[r, 1]
        votes = [values[r, c] for c in range(2, valid)]
        valid_votes, rejected, nota, total, tendered = [values[r, c] for c in range(valid, valid + 5)]
        if (station < 1 or station in seen_stations or sum(votes) != valid_votes
                or valid_votes + rejected + nota != total):
            continue
        seen_stations.add(station)
        proposals.append({'page': page, 'source_sha256': source_sha256, 'source_url': source_url,
                          'polling_station': str(station),
                          'candidate_columns': [{'column': column, 'ocr_header': name, 'votes': vote}
                                                for column, (name, vote) in enumerate(zip(names, votes), 2)],
                          'valid_votes': valid_votes, 'rejected_votes': rejected, 'nota': nota,
                          'total_votes': total, 'tendered_votes': tendered,
                          'source_cells': [{'column': c, 'bbox': [xs[c], top, xs[c + 1], bottom],
                                            'ocr_value': values[r, c]} for c in columns],
                          'quality': 'unverified_cell_ocr',
                          'notes': ['All printed vote cells reconcile. Candidate headers are OCR text, not verified names; check names and votes against the official PDF.']})
    return proposals


def extract_page(pdf, page_number, source_url, expected_sha256=None, dpi=130):
    with pdf.open('rb') as stream:
        digest = hashlib.file_digest(stream, 'sha256').hexdigest()
    if expected_sha256 is not None and digest != expected_sha256:
        raise ValueError('Preserved PDF checksum changed')
    with fitz.open(pdf) as document:
        pix = document[page_number - 1].get_pixmap(dpi=dpi, colorspace=fitz.csGRAY)
        gray = np.frombuffer(pix.samples, dtype=np.uint8).reshape(pix.height, pix.width).copy()
    grid = grid_lines(gray)
    if grid is None:
        return {'source_sha256': digest, 'source_url': source_url, 'page': page_number, 'dpi': dpi,
                'quality': 'needs_visual_review', 'proposed_polling_rows': []}
    xs, header_top, data_top, rows = grid
    layout = _header_layout(gray, xs, header_top, data_top)
    if layout is None:
        return {'source_sha256': digest, 'source_url': source_url, 'page': page_number, 'dpi': dpi,
                'quality': 'needs_visual_review', 'proposed_polling_rows': []}
    valid, names = layout
    values = _read_cells(gray, xs, rows, range(1, valid + 5))
    proposals = reconciled_rows(values, rows, valid, names, page_number, digest, source_url, xs)
    return {'source_sha256': digest, 'source_url': source_url, 'page': page_number, 'dpi': dpi,
            'adapter': 'form20-cell-grid-v1', 'quality': 'unverified_cell_ocr',
            'candidate_names_ocr': names, 'detected_rows': len(rows),
            'candidate_header_cells': [{'column': column, 'bbox': [xs[column], header_top,
                                                                    xs[column + 1], data_top],
                                        'ocr_header': name} for column, name in enumerate(names, 2)],
            'proposed_polling_rows': proposals,
            'notes': ['Only complete rows matching both printed arithmetic totals are proposed; other rows remain in the official PDF for review.']}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('pdf', type=Path)
    parser.add_argument('--page', type=int, required=True)
    parser.add_argument('--source-url', required=True)
    parser.add_argument('--expected-sha256', required=True)
    parser.add_argument('--dpi', type=int, choices=[130, 200], default=130)
    parser.add_argument('--output', type=Path, required=True)
    args = parser.parse_args()
    result = extract_page(args.pdf, args.page, args.source_url, args.expected_sha256, args.dpi)
    body = json.dumps(result, ensure_ascii=False, indent=2).encode('utf-8')
    args.output.parent.mkdir(parents=True, exist_ok=True)
    temporary = args.output.with_suffix(args.output.suffix + '.partial')
    temporary.write_bytes(body)
    temporary.replace(args.output)
    args.output.with_suffix(args.output.suffix + '.sha256').write_text(
        hashlib.sha256(body).hexdigest() + '  ' + args.output.name + '\n', encoding='ascii')
    print(json.dumps({'page': args.page, 'detected_rows': result.get('detected_rows', 0),
                      'proposed_rows': len(result['proposed_polling_rows'])}))
