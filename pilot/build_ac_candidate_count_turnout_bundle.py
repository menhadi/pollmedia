"""Preserve official summary corroboration for AC turnout held by candidate-count warnings."""

import argparse
import copy
import csv
import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_ac_zero_turnout_bundle import import_script
from extract_state_election_2002 import summary_number
from preserve_archive_json import package


NAME = 'pollmedia-ac-candidate-count-turnout-20261002'
EDITIONS = {'0568bae81d96e55877d1807e': 1974, '1cc8415ab4d57b66831417e8': 1996}
WARNING = 'Candidate count differs from summary'
NOTE = ('Official detailed and summary turnout totals agree. The summary candidate count differs '
        'from the detailed rows; candidate comparisons remain under review.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def file_digest(path: Path) -> str:
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def normalize(value: str) -> str:
    return re.sub(r'[^a-z0-9]', '', value.lower())


def audit_targets(path: Path) -> dict[str, dict]:
    targets = {}
    with path.open(encoding='utf-8-sig', newline='') as source:
        for row in csv.DictReader(source):
            edition = row['edition_id']
            if (edition in EDITIONS and row['issue'] == 'source_turnout_hidden'
                    and row['source_note'] == WARNING):
                entry = targets.setdefault(edition, {'sha256': row['extraction_sha256'], 'codes': set()})
                if entry['sha256'] != row['extraction_sha256']:
                    raise ValueError('Audit contains multiple extraction versions: ' + edition)
                entry['codes'].add(int(row['code']))
    if set(targets) != set(EDITIONS) or {edition: len(value['codes']) for edition, value in targets.items()} != {
            '0568bae81d96e55877d1807e': 32, '1cc8415ab4d57b66831417e8': 2}:
        raise ValueError('Live audit candidate-count inventory differs')
    return targets


def summary_values(page: fitz.Page, record: dict) -> dict | None:
    heading = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', page.get_text())
    if not heading or int(heading[1]) != record['code'] or normalize(heading[2]) != normalize(record['name']):
        return None
    try:
        return {'electors': summary_number(page, 'TOTAL', 450, 510, 280, 350),
                'votes_polled': summary_number(page, 'POLLED', 260, 320),
                'valid_candidate_votes': summary_number(page, 'VALID', 260, 320)}
    except ValueError:
        return None


def eligible(record: dict, summary: dict | None) -> bool:
    candidates = record.get('candidates') or []
    return (summary is not None and record.get('status') == 'needs_review'
            and record.get('error') == WARNING and record.get('number_of_seats') == 1
            and isinstance(record.get('detail_page'), int) and record['detail_page'] > 0
            and isinstance(record.get('summary_page'), int) and record['summary_page'] > 0
            and all(type(record.get(key)) is int and record[key] == summary[key]
                    for key in ('electors', 'votes_polled', 'valid_candidate_votes'))
            and 0 < summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']
            and len(candidates) >= 2
            and all(type(candidate.get('votes')) is int and candidate['votes'] >= 0 for candidate in candidates)
            and sum(candidate['votes'] for candidate in candidates) == summary['valid_candidate_votes']
            and type(record.get('reported_candidate_count')) is int
            and record['reported_candidate_count'] != len(candidates))


def build(root: Path, audit_csv: Path) -> dict:
    targets = audit_targets(audit_csv)
    exports = root / 'exports'
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    details = []
    with tempfile.TemporaryDirectory(prefix='ac-candidate-count-', dir=exports) as temporary:
        staging = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        for edition, year in EDITIONS.items():
            folder = root / 'application/storage/app/private/election-archive' / edition
            old_body = (folder / 'extraction.json').read_bytes()
            if digest(old_body) != targets[edition]['sha256']:
                raise ValueError('Live audit and local extraction checksums differ: ' + edition)
            data = json.loads(old_body)
            if data['kind'] != 'ac' or data['year'] != year or len({r['code'] for r in data['records']}) != len(data['records']):
                raise ValueError('Election edition identity differs: ' + edition)
            manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
            source = folder / data['source_file']
            source_entries = [item for item in manifest['files'] if item['file'] == data['source_file']]
            if (manifest['url'] != data['source_url'] or len(source_entries) != 1
                    or source.resolve().parent != folder.resolve() or source.is_symlink()
                    or data['source_sha256'] != source_entries[0]['sha256']
                    or file_digest(source) != data['source_sha256']):
                raise ValueError('Official PDF checksum differs: ' + edition)
            revised = copy.deepcopy(data)
            recovered, held = [], []
            with fitz.open(source) as document:
                for record in revised['records']:
                    if record['code'] not in targets[edition]['codes']:
                        continue
                    page = record.get('summary_page')
                    values = summary_values(document[page - 1], record) if isinstance(page, int) and 1 <= page <= len(document) else None
                    if not eligible(record, values):
                        held.append(record['code'])
                        continue
                    record['original_extraction_warning'] = record['error']
                    record['error'] = NOTE
                    record['source_warning_code'] = 'official_summary_turnout_only'
                    record['summary_totals'] = values
                    record['summary_source_file'] = data['source_file']
                    record['summary_source_sha256'] = data['source_sha256']
                    recovered.append(record['code'])
            if (edition == '0568bae81d96e55877d1807e' and (len(recovered), held) != (32, [])
                    or edition == '1cc8415ab4d57b66831417e8' and (recovered, held) != ([399], [400])):
                raise ValueError('Expected source corroboration changed: ' + edition)
            for before, after in zip(data['records'], revised['records']):
                protected = ('code', 'name', 'electors', 'votes_polled', 'valid_candidate_votes',
                             'candidates', 'number_of_seats', 'status', 'detail_page', 'summary_page')
                if any(before.get(key) != after.get(key) for key in protected):
                    raise ValueError('Original votes or identity changed: ' + edition)
            new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
            snapshot = f'election-archive/{edition}/extraction-{digest(old_body)}.json'
            revision = f'election-archive/{edition}/extraction.json'
            for relative, body in ((snapshot, old_body), (revision, new_body)):
                path = staging / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(body)
            details.append({'edition': edition, 'year': year, 'source_url': data['source_url'],
                            'source_sha256': data['source_sha256'], 'previous_sha256': digest(old_body),
                            'new_sha256': digest(new_body), 'shown_turnout_codes': recovered,
                            'held_codes': held})
        inner = []
        for prefix in ('snapshot', 'correction'):
            for detail in details:
                edition = detail['edition']
                prior = f'election-archive/{edition}/extraction-{detail["previous_sha256"]}.json'
                relative = prior if prefix == 'snapshot' else f'election-archive/{edition}/extraction.json'
                item = packages / f'{prefix}-{edition}.zip'
                bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
                package(staging, item, 'election-archive', bucket, 8, [relative],
                        detail['previous_sha256'] if prefix == 'correction' else None,
                        prior if prefix == 'correction' else None)
                inner.append(item)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for item in inner:
                archive.write(item, item.name)
            archive.writestr('SHA256SUMS', ''.join(f'{file_digest(item)}  {item.name}\n' for item in inner))
            archive.writestr('ARCHIVES', ''.join(detail['edition'] + '\n' for detail in details))
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Only 33 summary-corroborated AC turnout rows; original votes and candidate rows unchanged',
                                                      'editions': details}, ensure_ascii=False, indent=2))
            archive.writestr('IMPORT.sh', import_script([detail['edition'] for detail in details]))
        partial.replace(output)
    checksum = file_digest(output)
    output.with_suffix('.sha256').write_bytes((checksum + '  ' + output.name + '\n').encode('ascii'))
    return {'bundle': str(output), 'sha256': checksum, 'turnout_rows': 33, 'held_rows': 1}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('audit_csv', type=Path)
    args = parser.parse_args()
    print(json.dumps(build(Path(__file__).resolve().parents[1], args.audit_csv)))
