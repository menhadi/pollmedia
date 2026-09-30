"""Find and optionally preserve verified Gujarat 2012 candidate vote corrections."""
import argparse
import hashlib
import json
from pathlib import Path
import re


def compact(value):
    return re.sub(r'[^A-Z0-9]', '', str(value or '').upper())


def number(value):
    value = str(value or '').replace(',', '').strip()
    return int(value) if value.isdigit() else None


def total_table(cells):
    if len(cells) < 4:
        return None
    valid_columns = [column for row in cells[:4] for column, cell in enumerate(row)
                     if 'TOTALOFVALIDVOTES' in compact(cell)]
    if len(set(valid_columns)) != 1:
        return None
    valid_column = valid_columns[0]
    if valid_column < 3:
        return None
    for row in reversed(cells):
        label = compact(row[0] if row else '')
        if label != 'TOTAL' and not label.startswith('TOTALNOOFVOTESRECORDED'):
            continue
        if len(row) <= valid_column:
            continue
        totals = [number(row[column]) for column in range(1, valid_column)]
        valid_total = number(row[valid_column])
        if valid_total is None or any(value is None for value in totals) or sum(totals) != valid_total:
            continue
        names = {}
        for column in range(1, valid_column):
            options = [compact(header[column]) for header in cells[:4]
                       if len(header) > column and compact(header[column])
                       and 'VALIDVOTES' not in compact(header[column])]
            names[column] = max(options, key=len) if options else ''
        return names, totals, valid_total
    return None


def matches_for_constituency(record, polling_source, polling_root):
    folder = polling_root / polling_source['folder']
    table_root = folder / (polling_source['sha256'] + '-tables')
    page_index = json.loads((table_root / 'index.json').read_text(encoding='utf-8'))
    if not page_index['pages']:
        return []
    page_entry = page_index['pages'][-1]
    page_file = table_root / page_entry['file']
    body = page_file.read_bytes()
    if hashlib.sha256(body).hexdigest() != page_entry['sha256']:
        raise ValueError('Preserved Form 20 page checksum differs: ' + str(page_file))
    page = json.loads(body)
    results = []
    for table_number, table in enumerate(page.get('tables', []), 1):
        parsed = total_table(table.get('cells', []))
        if not parsed:
            continue
        headers, totals, _ = parsed
        by_name = {}
        for column, name in headers.items():
            by_name.setdefault(name, []).append((column, totals[column - 1]))
        known = 0
        conflict = False
        for candidate in record['candidates']:
            found = by_name.get(compact(candidate['candidate_name']), [])
            if candidate.get('votes') is not None and len(found) == 1:
                known += 1
                if candidate['votes'] != found[0][1]:
                    conflict = True
        if conflict or known < 2:
            continue
        for candidate_index, candidate in enumerate(record['candidates']):
            if candidate.get('votes') is not None:
                continue
            found = by_name.get(compact(candidate['candidate_name']), [])
            if len(found) != 1:
                continue
            column, votes = found[0]
            general, postal = candidate.get('general_votes'), candidate.get('postal_votes')
            if general is not None and postal is not None and general + postal != votes:
                continue
            results.append({'record_code': record['code'], 'constituency': record['name'],
                            'candidate_index': candidate_index, 'candidate_name': candidate['candidate_name'],
                            'votes': votes, 'page': page_entry['page'], 'table': table_number,
                            'column': column, 'known_matches': known,
                            'source_url': polling_source['source_url'], 'source_sha256': polling_source['sha256'],
                            'page_sha256': page_entry['sha256']})
    return results


def audit(root):
    archive = root / 'application/storage/app/private/election-archive/503135d3e838d38c93d3bce7/extraction.json'
    records = json.loads(archive.read_text(encoding='utf-8'))['records']
    polling_root = root / 'application/storage/app/private/polling-station-sources'
    polling_index = json.loads((polling_root / 'index.json').read_text(encoding='utf-8'))
    sources = {int(match.group(1)): source for source in polling_index['sources']
               if (match := re.fullmatch(r'https://ceo\.gujarat\.gov\.in/download/Form20_2012/AC([0-9]{3})\.PDF', source['source_url']))}
    proposed = []
    for record in records:
        if any(candidate.get('votes') is None for candidate in record['candidates']) and record['code'] in sources:
            proposed.extend(matches_for_constituency(record, sources[record['code']], polling_root))
    return {'missing_candidate_votes': sum(candidate.get('votes') is None for record in records for candidate in record['candidates']),
            'candidate_votes_with_independent_form20_total': len(proposed), 'proposals': proposed}


def apply(root, result):
    archive = root / 'application/storage/app/private/election-archive/503135d3e838d38c93d3bce7/extraction.json'
    original = archive.read_bytes()
    original_sha256 = hashlib.sha256(original).hexdigest()
    data = json.loads(original)
    source_file = archive.parent / data['source_file']
    with source_file.open('rb') as stream:
        if hashlib.file_digest(stream, 'sha256').hexdigest() != data['source_sha256']:
            raise ValueError('Original Gujarat report checksum differs')
    polling_root = root / 'application/storage/app/private/polling-station-sources'
    polling_index = json.loads((polling_root / 'index.json').read_text(encoding='utf-8'))
    by_url = {source['source_url']: source for source in polling_index['sources']}
    verified = set()
    for proposal in result['proposals']:
        source = by_url[proposal['source_url']]
        if source['sha256'] != proposal['source_sha256']:
            raise ValueError('Form 20 index checksum differs')
        if source['source_url'] not in verified:
            pdf = polling_root / source['folder'] / (source['sha256'] + '.pdf')
            with pdf.open('rb') as stream:
                if hashlib.file_digest(stream, 'sha256').hexdigest() != source['sha256']:
                    raise ValueError('Original Form 20 checksum differs: ' + source['source_url'])
            verified.add(source['source_url'])
    records = {record['code']: record for record in data['records']}
    seen = set()
    for proposal in result['proposals']:
        key = (proposal['record_code'], proposal['candidate_index'])
        if key in seen:
            raise ValueError('Multiple source cells proposed for one candidate')
        seen.add(key)
        candidate = records[proposal['record_code']]['candidates'][proposal['candidate_index']]
        if candidate['candidate_name'] != proposal['candidate_name'] or candidate.get('votes') is not None:
            raise ValueError('Candidate changed since the source audit')
        candidate['votes'] = proposal['votes']
        candidate['vote_evidence'] = {
            'source_url': proposal['source_url'], 'source_sha256': proposal['source_sha256'],
            'page': proposal['page'], 'page_sha256': proposal['page_sha256'],
            'table': proposal['table'], 'column': proposal['column'],
            'printed_total': proposal['votes'], 'known_candidate_totals_matched': proposal['known_matches'],
        }
    snapshot = archive.with_name('extraction-' + original_sha256 + '.json')
    if snapshot.exists():
        if snapshot.read_bytes() != original:
            raise ValueError('Existing extraction snapshot differs')
    else:
        snapshot.write_bytes(original)
    replacement = json.dumps(data, ensure_ascii=False, separators=(',', ':')).encode('utf-8')
    temporary = archive.with_suffix('.json.partial')
    if temporary.exists():
        raise FileExistsError('Extraction temporary file already exists')
    temporary.write_bytes(replacement)
    temporary.replace(archive)
    return {'old_sha256': original_sha256, 'new_sha256': hashlib.sha256(replacement).hexdigest(),
            'snapshot': snapshot.as_posix(), 'corrected_votes': len(result['proposals'])}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--apply', action='store_true', help='Preserve the prior bytes and write verified votes')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]
    result = audit(root)
    if args.apply:
        result['applied'] = apply(root, result)
    print(json.dumps({**result, 'proposals': result['proposals'][:20]}, indent=2))
