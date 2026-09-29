"""Preserve table-cell evidence from one registered public UP report snapshot."""
import hashlib
import json
from datetime import datetime, timezone
from html.parser import HTMLParser

ORIGINAL_SHA256 = '05535ffe2264ab05ac873dbe79ddda1df2aa849029e5c8a0b0bef3716809944a'


class TableEvidence(HTMLParser):
    def __init__(self, source):
        super().__init__(convert_charrefs=True)
        self.source = source
        self.offsets = [0]
        for line in source.splitlines(keepends=True):
            self.offsets.append(self.offsets[-1] + len(line))
        self.stack = []
        self.tables = 0
        self.cells = []

    def source_offset(self):
        line, column = self.getpos()
        return self.offsets[line - 1] + column

    def handle_starttag(self, tag, attrs):
        if tag == 'table':
            self.tables += 1
            self.stack.append({'table': self.tables, 'row': 0, 'cell': 0, 'active': None})
        elif self.stack:
            table = self.stack[-1]
            if tag == 'tr':
                if table['active'] is not None:
                    raise ValueError('Unclosed table cell')
                table['row'] += 1
                table['cell'] = 0
            elif tag in ('td', 'th'):
                if table['active'] is not None or not table['row']:
                    raise ValueError('Unexpected table cell structure')
                table['cell'] += 1
                table['active'] = dict(table=table['table'], row=table['row'],
                    cell=table['cell'], tag=tag, attributes=attrs,
                    start_character=self.source_offset(), text_parts=[])
            elif tag == 'br' and table['active'] is not None:
                table['active']['text_parts'].append('\n')

    def handle_data(self, data):
        if self.stack and self.stack[-1]['active'] is not None:
            self.stack[-1]['active']['text_parts'].append(data)

    def handle_endtag(self, tag):
        if not self.stack:
            return
        table = self.stack[-1]
        if tag in ('td', 'th'):
            cell = table['active']
            if cell is None or tag != cell['tag']:
                raise ValueError('Mismatched table cell closing tag')
            end = self.source.find('>', self.source_offset()) + 1
            if not end:
                raise ValueError('Incomplete closing tag')
            cell['end_character'] = end
            cell['raw_html'] = self.source[cell['start_character']:end]
            cell['text'] = ''.join(cell.pop('text_parts'))
            self.cells.append(cell)
            table['active'] = None
        elif tag == 'table':
            if table['active'] is not None:
                raise ValueError('Unclosed table cell')
            self.stack.pop()


def parse(source):
    parser = TableEvidence(source)
    parser.feed(source)
    parser.close()
    if parser.stack or not parser.cells:
        raise ValueError('Incomplete or empty table evidence')
    return sorted(parser.cells, key=lambda cell: cell['start_character'])


def extract(root, package, expected, job, digest, resources_ok):
    from census_server_worker import ResourceWait
    if expected != ORIGINAL_SHA256 or digest(package) != expected:
        raise ValueError('Unregistered or changed MGNREGA HTML original')
    if not resources_ok(root):
        raise ResourceWait('Waiting for disk/RAM reserve')
    source = package.read_bytes().decode('utf-8')
    if 'Period Wise Employment' not in source or 'Enter Captcha' in source:
        raise ValueError('Expected employment report, not an access challenge')
    cells = parse(source)
    evidence = root / 'source-evidence'
    evidence.mkdir(exist_ok=True)
    path = evidence / ('mgnrega-html-' + expected + '.cells.jsonl')
    temporary = path.with_suffix('.partial')
    with temporary.open('w', encoding='utf-8', newline='\n') as output:
        for cell in cells:
            output.write(json.dumps(cell, ensure_ascii=False) + '\n')
    if not resources_ok(root):
        temporary.unlink(missing_ok=True)
        raise ResourceWait('Waiting for disk/RAM reserve')
    temporary.replace(path)
    receipt = dict(original_sha256=expected, source_url=job['source_url'],
        extracted_at=datetime.now(timezone.utc).isoformat(), encoding='utf-8',
        cells=len(cells), cells_sha256=digest(path),
        locator_definition='1-based table/row/cell in source order; 0-based character offsets in decoded original; spans remain attributes',
        review_state='PENDING ADMIN REVIEW',
        limitations=['No inferred expanded column grid or geography joins',
                    'No arithmetic or semantic validation yet',
                    '2026-2027 year-to-date snapshot; household employment and person-days are distinct'])
    target = evidence / ('mgnrega-html-' + expected + '.manifest.json')
    temporary = target.with_suffix('.tmp')
    temporary.write_text(json.dumps(receipt, indent=2), encoding='utf-8')
    temporary.replace(target)
