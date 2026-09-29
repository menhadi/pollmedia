"""Stream already-staged amenity cells into private, reviewable viewer pages.

No workbook extraction, source acquisition, database writes or live installation.
"""
import hashlib
import json


PERIOD_NOTE = ('Publication edition 2011. Main village/town Reference Year is 2009; '
               'this does not establish the period of every field. No current-boundary joins are implied.')


def encode(value):
    return json.dumps(value, ensure_ascii=False, separators=(',', ':')).encode('utf-8')


def prepare_sheet(connection, stream, workbook_hash, name, header_row, page_size=100):
    """Bound memory to one page; retain sparse row numbers and original JSON types."""
    if page_size < 1 or header_row < 1:
        raise ValueError('Positive page size and header row required')
    records = connection.execute(
        'SELECT source_row, cells_json, cell_types_json FROM raw_rows '
        'WHERE workbook_sha256=? AND sheet=? ORDER BY source_row', (workbook_hash, name))
    header = None
    preamble, batch, pages = [], [], []
    count = width = 0
    previous = 0
    input_digest = hashlib.sha256()

    def flush():
        if not batch:
            return
        body = encode(batch)
        if len(body) > 8 * 1024 * 1024:
            raise ValueError('Viewer page exceeds 8 MiB; reduce page size')
        pages.append({'offset': stream.tell(), 'length': len(body),
                      'sha256': hashlib.sha256(body).hexdigest()})
        stream.write(body + b'\n')
        batch.clear()

    for source_row, cells_json, types_json in records:
        if source_row <= previous:
            raise ValueError('Duplicate or out-of-order source row')
        previous = source_row
        cells, types = json.loads(cells_json), json.loads(types_json)
        if not isinstance(cells, list) or not isinstance(types, list) or len(cells) != len(types):
            raise ValueError('Cell/type alignment differs')
        item = {'source_row': source_row, 'cells': cells, 'cell_types': types}
        input_digest.update(encode(item) + b'\n')
        if source_row < header_row:
            preamble.append(item)
            continue
        if source_row == header_row:
            header = item
            width = len(cells)
            continue
        if header is None:
            raise ValueError('Reviewed header row missing')
        width = max(width, len(cells))
        item.update(formula_columns=[i for i, t in enumerate(types) if t == 'f'],
                    error_columns=[i for i, t in enumerate(types) if t == 'e'],
                    flags=[PERIOD_NOTE])
        if name.startswith(('Slum', 'Hamlet')):
            item['flags'].append('Detail rows repeat parent codes/population; do not sum parent population.')
        batch.append(item)
        count += 1
        if len(batch) == page_size:
            flush()
    if header is None:
        raise ValueError('Reviewed header row missing')
    flush()
    result = {'name': name, 'headers': header['cells'] + [None] * (width - len(header['cells'])),
              'header_source_row': header_row, 'header_cell_types': header['cell_types'],
              'row_count': count, 'pages': pages, 'districts': [],
              'staged_sheet_sha256': input_digest.hexdigest()}
    # Caller must expose these in a supplementary worksheet; viewer ignores preamble metadata.
    return result, preamble


def prepare_preamble(stream, original_name, rows):
    """Expose title/notes preceding a reviewed header instead of silently omitting them."""
    if not rows:
        return None
    width = max(len(row['cells']) for row in rows)
    visible = [dict(row, flags=['Source title/notes preceding the data header; not observations.'])
               for row in rows]
    body = encode(visible)
    if len(body) > 8 * 1024 * 1024:
        raise ValueError('Source notes exceed viewer page limit')
    page = {'offset': stream.tell(), 'length': len(body), 'sha256': hashlib.sha256(body).hexdigest()}
    stream.write(body + b'\n')
    return {'name': original_name + ' — source title and notes',
            'headers': ['Original column ' + str(i + 1) for i in range(width)],
            'header_source_row': None, 'original_worksheet': original_name,
            'row_count': len(rows), 'pages': [page], 'districts': []}
