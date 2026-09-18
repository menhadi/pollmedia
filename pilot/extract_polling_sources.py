"""Extract preserved Form 20 PDF tables with page references and reconciliation notes."""
import argparse
from concurrent.futures import ProcessPoolExecutor
import hashlib
import json
from pathlib import Path
import re
import fitz
from polling_manifest import load_manifest


def normalized(value):
    return re.sub(r'[^a-z0-9]', '', str(value or '').lower())


def numeric(value):
    if isinstance(value, float) and value.is_integer() and value >= 0:
        return int(value)
    cleaned = str('' if value is None else value).replace(',', '').strip()
    return int(cleaned) if re.fullmatch(r'\d+', cleaned) else None


def map_table(cells):
    if len(cells) < 3:
        return []
    header = next((i for i,row in enumerate(cells[:32]) if any('pollingstation' in normalized(v) for v in row)
                   and any('votescastinfavour' in normalized(v) or 'votescastinfavor' in normalized(v) for v in row)), None)
    if header is None or header+2 >= len(cells):
        return []
    first = [normalized(v) for v in cells[header]]
    start = next((i for i,v in enumerate(first) if 'votescastinfavourof' in v or 'votescastinfavorof' in v), None)
    station_column = next((i for i,v in enumerate(first) if 'pollingstation' in v), None)
    if (start is not None and station_column is not None and station_column < start
            and all(str(v or '').strip() for v in cells[header+1][station_column+1:start])):
        start = station_column + 1
    valid_labels = ['totalofvalidvotes','totalvalidvotes','totalnoofvalidvotes','totalnumberofvalidvotes']
    end = next((i for i,v in enumerate(first) if v in valid_labels), None)
    if start is None or end is None or start >= end or start < 1:
        return []
    names = [' '.join(str(v or '').split()) for v in cells[header+1][start:end]]
    if not all(names) or any(numeric(name) is not None for name in names) or len(names) != end-start:
        return []
    totals = {k: next((i for i,v in enumerate(first) if test(v)), None) for k,test in {
        'valid_votes': lambda v: v in valid_labels,
        'rejected_votes': lambda v: 'rejectedvotes' in v,
        'nota': lambda v: 'nota' in v,
        'total_votes': lambda v: v == 'total',
        'tendered_votes': lambda v: 'tenderedvotes' in v,
    }.items()}
    output = []
    for index,row in enumerate(cells[header+2:], header+3):
        if len(row) != len(first):
            continue
        serial = numeric(row[0])
        station_value = row[start-1]
        if isinstance(station_value, float) and station_value.is_integer():
            station_value = int(station_value)
        station = ' '.join(str(station_value if station_value is not None else '').split())
        numbered_station = re.fullmatch(r'\d+(?:\s*[-/]?\s*[A-Za-z]|\([A-Za-z]\))?', station)
        named_station = re.fullmatch(r'\d+[A-Za-z]?(?:\s*[-–:]\s*|\s+)[^\d\s].*', station)
        if serial is None or not (numbered_station or named_station):
            continue
        votes = [numeric(v) for v in row[start:end]]
        values = {k:numeric(row[i]) if i is not None else None for k,i in totals.items()}
        notes = []
        if any(column is not None and values[key] is None and str(row[column] or '').strip() for key,column in totals.items()):
            notes.append('One or more source totals contain a formula or unreadable value; no total has been inferred.')
        if any(v is None for v in votes):
            notes.append('One or more candidate vote cells could not be read as a whole number.')
        elif values['valid_votes'] is not None and sum(votes) != values['valid_votes']:
            notes.append('Candidate votes do not equal the source valid-vote total.')
        if all(values[k] is not None for k in ['valid_votes','rejected_votes','nota','total_votes']) and values['valid_votes']+values['rejected_votes']+values['nota'] != values['total_votes']:
            notes.append('Valid, rejected and NOTA votes do not reconcile with the source total.')
        output.append({'source_table_row':index, 'serial':serial, 'polling_station':station,
                       'candidate_votes':[{'name':name,'votes':vote} for name,vote in zip(names,votes)], **values,
                       'notes':notes, 'source_cells':row})
    return output


def spreadsheet_pages(source):
    from extract_assembly_modern import load_cells
    from datetime import date, datetime
    book = load_cells(source)
    try:
        for index, sheet in enumerate(book, 1):
            cells = []
            for row in sheet.values:
                if len(cells) >= 20000 or len(row) > 100:
                    raise ValueError('Worksheet dimensions exceed extraction limits')
                cells.append([v.isoformat() if isinstance(v, (date, datetime)) else v for v in row])
            notes = list(getattr(book, 'reader_notes', []))
            notes.append('Worksheet cells are preserved in source order. Formulas are not recalculated; stored XLS results may be stale. Check the original workbook.')
            mapped = [r | {'table': 1} for r in map_table(cells)]
            if not mapped:
                notes.append('Source tables extracted; polling-row layout still requires mapping.')
            yield {'page': index, 'sheet': sheet.title, 'text': '', 'tables': [{'number': 1, 'cells': cells}],
                   'polling_rows': mapped, 'notes': notes}
    finally:
        book.close()


def extract(job):
    folder, item = job
    folder = Path(folder)
    source = folder/item['file']
    digest = hashlib.sha256(source.read_bytes()).hexdigest()
    if digest != item['sha256']:
        return {'source_file': item['file'], 'error':'Source checksum changed'}
    destination = folder/(digest+'-tables')
    destination.mkdir(exist_ok=True)
    manifest_path = destination/'index.json'
    if manifest_path.exists():
        saved = json.loads(manifest_path.read_text(encoding='utf-8'))
        for page in saved['pages']:
            path = destination/page['file']
            if path.parent != destination or not path.is_file() or hashlib.sha256(path.read_bytes()).hexdigest() != page['sha256']:
                return {'source_file':item['file'], 'error':'Saved page checksum failed; preserved extraction requires repair'}
        return saved
    spreadsheet = source.suffix.lower() in ['.xls', '.xlsx']
    document = spreadsheet_pages(source) if spreadsheet else fitz.open(source)
    pages = []
    for index,page in enumerate(document):
        output_path = destination/(str(index+1)+'.json')
        if output_path.exists():
            body = output_path.read_bytes(); data = json.loads(body)
        elif spreadsheet:
            data = page
            body = json.dumps(data, ensure_ascii=False).encode('utf-8')
            temporary = output_path.with_suffix('.tmp')
            temporary.write_bytes(body)
            temporary.replace(output_path)
        else:
            content = page.get_text()
            data = {'page':index+1, 'text':content, 'tables':[], 'polling_rows':[], 'notes':[]}
            if len(content.strip()) < 40:
                data['notes'].append('Scanned or empty page; OCR and visual checking are required.')
            else:
                try:
                    for table_number,table in enumerate(page.find_tables().tables, 1):
                        cells = table.extract()
                        data['tables'].append({'number':table_number,'cells':cells})
                        data['polling_rows'].extend(row | {'table':table_number} for row in map_table(cells))
                    if data['tables'] and not data['polling_rows']:
                        data['notes'].append('Source tables extracted; polling-row layout still requires mapping.')
                    if not data['tables']:
                        data['notes'].append('Page text was extracted, but no table grid was recognised; layout review or OCR is required.')
                except Exception as error:
                    data['notes'].append('Table extraction failed: '+str(error))
            body = json.dumps(data,ensure_ascii=False).encode('utf-8')
            temporary = output_path.with_suffix('.tmp')
            temporary.write_bytes(body)
            temporary.replace(output_path)
        pages.append({'page':index+1,'sheet':data.get('sheet'),'file':output_path.name,'sha256':hashlib.sha256(body).hexdigest(),
                      'tables':len(data['tables']),'polling_rows':len(data['polling_rows']),
                      'flagged_rows':sum(bool(r['notes']) for r in data['polling_rows']), 'notes':data['notes']})
    document.close()
    result = {'adapter':'form20-grid-v4', 'source_url':item['url'],'source_file':item['file'],'source_sha256':digest,
              'pages':pages,'page_count':len(pages),'polling_rows':sum(p['polling_rows'] for p in pages),
              'scope_note':'Counts are extracted source rows, not unique national polling stations. Postal, aggregate and unrecognised rows remain in original tables; no current geography mapping is implied.'}
    temporary = manifest_path.with_suffix('.tmp')
    temporary.write_text(json.dumps(result,ensure_ascii=False,indent=2),encoding='utf-8')
    temporary.replace(manifest_path)
    print(item['file'][:12]+': '+str(len(pages))+' pages; '+str(result['polling_rows'])+' polling rows',flush=True)
    return result


def extract_safely(job):
    try:
        return extract(job)
    except Exception as error:
        return {'source_file':job[1]['file'], 'error':str(error)}


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--workers',type=int,choices=[1,2,3,4],default=2);parser.add_argument('--state',action='append');args=parser.parse_args()
    root=Path(__file__).resolve().parents[1]/'application/storage/app/private/polling-station-sources'
    jobs=[]
    for path in root.glob('*/manifest.json'):
        manifest=load_manifest(path);seen=set()
        if args.state and manifest['state'] not in args.state:
            continue
        for item in manifest['documents']:
            if item.get('file','').endswith(('.pdf', '.xls', '.xlsx')) and item['file'] not in seen:
                jobs.append((str(path.parent),item));seen.add(item['file'])
    with ProcessPoolExecutor(max_workers=args.workers) as pool:
        results=list(pool.map(extract_safely,jobs))
    summary_name='extraction-summary-'+hashlib.sha256('|'.join(sorted(args.state or [])).encode()).hexdigest()[:12]+'.json' if args.state else 'extraction-summary.json'
    (root/summary_name).write_text(json.dumps({'documents':len(results),'pages':sum(r.get('page_count',0) for r in results),'polling_rows':sum(r.get('polling_rows',0) for r in results),
         'errors':[r for r in results if 'error' in r]},indent=2),encoding='utf-8')
