"""Extract names using column coordinates from the reviewed Pilibhit table layout."""
import argparse, hashlib, json, re
from pathlib import Path
import pdfplumber
import pypdfium2 as pdfium


def extract(pdf_path, manifest, parts_limit):
    if hashlib.sha256(pdf_path.read_bytes()).hexdigest() != manifest['sha256']:
        raise ValueError('Source PDF checksum mismatch')
    records, parts, sequences, dates, held = [], set(), {}, set(), []
    native = pdfium.PdfDocument(pdf_path)
    with pdfplumber.open(pdf_path) as pdf:
        for page_number, page in enumerate(pdf.pages, 1):
            text = page.extract_text() or ''
            found = re.search(r'AC:\s*(\d+)-([^;]+);\s*Part:\s*(\d+)-([^\n]+)', text)
            if not found and not parts:
                raise ValueError(f'Missing geography on page {page_number}')
            if found:
                ac, ac_name, part, station = found.groups()
                part = int(part)
            if part not in parts and len(parts) >= parts_limit:
                break
            if ac != '127' or abs(page.width - 595.28) > 1:
                raise ValueError('Unsupported PDF geography or layout')
            parts.add(part)
            dates.update(re.findall(r'Date of Generation:\s*(\d+/\d+/\d+)', text))
            words = page.extract_words()
            native_page = native[page_number-1]
            native_text = native_page.get_textpage()
            headers = [w['bottom'] for w in words if w['text'] == 'S.No.']
            if len(headers) != 1:
                raise ValueError('Missing table header')
            columns = {key: next(w['x0']-1 for w in words if w['text'] == key and w['bottom'] <= headers[0]+1) for key in ['Serial', 'EPIC', 'Elector', 'Relative', 'Uncollectable']}
            footer = min(w['top'] for w in words if w['text'] in ['Note:', 'Page'] and w['top'] > 700)
            anchors = [w for w in words if w['x0'] < 45 and headers[0] < w['top'] < footer and w['text'].isdigit()]
            for index, anchor in enumerate(anchors):
                end = anchors[index+1]['top']-1 if index+1 < len(anchors) else footer-1
                row = [w for w in words if anchor['top']-1 <= w['top'] < end]
                def column(left, right):
                    return ' '.join(native_text.get_text_bounded(left, page.height-end, right, page.height-anchor['top']+2).split())
                name = column(columns['Elector'], columns['Relative'])
                relative = column(columns['Relative'], columns['Uncollectable'])
                relation = re.fullmatch(r'(.+) \((Father|Mother|Husband|Wife|Other)\)', relative)
                serial = [w['text'] for w in row if columns['Serial'] <= w['x0'] < columns['EPIC']]
                sequences.setdefault(part, []).append(int(anchor['text']))
                if not name or not relation or len(serial) != 1 or not serial[0].isdigit():
                    held.append(dict(part=part, pdf_page=page_number, row=int(anchor['text'])))
                    continue
                records.append(dict(part=part, station=station.strip(), serial=int(serial[0]), name=name,
                                    relative_name=relation[1], relationship=relation[2], pdf_page=page_number))
            native_text.close()
            native_page.close()
    for part, sequence in sequences.items():
        if sequence != list(range(1, len(sequence)+1)):
            raise ValueError(f'Incomplete rows in part {part}')
    if len(dates) != 1:
        raise ValueError('Inconsistent document dates')
    d, m, y = next(iter(dates)).split('/')
    return dict(edition_key=manifest['sha256'], state_code='09', ac_code=ac, ac_name=ac_name.strip(),
                year=None, edition='Uncollected enumeration forms', document_date=f'{y}-{m}-{d}',
                source_url=manifest['url'], records=records, held_rows=held)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--parts', type=int, default=20)
    parser.add_argument('--output', required=True)
    args = parser.parse_args()
    root = Path(__file__).resolve().parent
    manifest = next(r for r in json.loads((root/'acquisition.json').read_text()) if r['id']=='sir-pilibhit-uncollected')
    result = extract(root/Path(manifest['path'].replace('\\','/')), manifest, args.parts)
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(result, ensure_ascii=False), encoding='utf-8')
    print(json.dumps(dict(records=len(result['records']), held_rows=len(result['held_rows']), parts=len({r['part'] for r in result['records']}), sha256=hashlib.sha256(output.read_bytes()).hexdigest())))
