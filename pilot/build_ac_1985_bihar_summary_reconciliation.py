"""Reconcile 323 Bihar 1985 AC tables with their official summary and detail pages."""

import csv
import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1992_summary_result_bundle import normalized
from build_pc_ac_zero_turnout_bundle import import_script
from extract_assembly_summary_totals import corroborates, read_summary_pages
from preserve_archive_json import package


EDITION = '60b51eb873eefae10d9f9aae'
SOURCE_FILE = EDITION + '-9213.pdf'
PREVIOUS_PACKAGE = 'pollmedia-pc-ac-zero-turnout-corrections-20261001-v3.zip'
NAME = 'pollmedia-ac-bihar-1985-summary-reconciliation-20261003'
EXPECTED_TARGETS = 323
RECONCILED_NOTE = ('Candidate rows transcribed from the detailed PDF; summary totals reconcile; '
                   'publication review pending.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def previous_body(root: Path) -> bytes:
    outer_path = root / 'exports' / PREVIOUS_PACKAGE
    if digest(outer_path.read_bytes()) != outer_path.with_suffix('.sha256').read_text().split()[0]:
        raise ValueError('Previous imported correction bundle checksum differs')
    with zipfile.ZipFile(outer_path) as outer:
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            path = f'election-archive/{EDITION}/extraction.json'
            manifest = json.loads(inner.read('manifest.json'))['files'][0]
            body = inner.read(path)
    if manifest['path'] != path or digest(body) != manifest['sha256']:
        raise ValueError('Previous imported Bihar 1985 JSON checksum differs')
    return body


def audited_targets(root: Path) -> tuple[str, set[int]]:
    with (root / 'exports/pc-ac-display-audit-after-bdd282d.csv').open(encoding='utf-8-sig', newline='') as source:
        rows = [row for row in csv.DictReader(source)
                if row['edition_id'] == EDITION and row['issue'] == 'source_turnout_hidden']
    codes = {int(row['code']) for row in rows}
    if (len(rows) != EXPECTED_TARGETS or len(codes) != EXPECTED_TARGETS
            or len({row['extraction_sha256'] for row in rows}) != 1):
        raise ValueError('Last live Bihar 1985 turnout audit differs')
    return rows[0]['extraction_sha256'], codes


def detail_sections(pdf: fitz.Document) -> dict[int, tuple[int, str, str]]:
    pages = [(index + 1, pdf[index].get_text(sort=True)) for index in range(len(pdf))]
    text = '\n'.join(page for _, page in pages)
    starts = []
    offset = 0
    for page_number, page in pages:
        for match in re.finditer(r'Constituency\s*:\s*(\d+)\s*\.\s*([^\n]+)', page, re.I):
            if page_number < 346:
                raise ValueError('Detailed constituency heading appears inside the summary section')
            starts.append((int(match[1]), page_number, match[2].strip(), offset + match.start(), offset + match.end()))
        offset += len(page) + 1
    if len(starts) != 324 or {item[0] for item in starts} != set(range(1, 325)):
        raise ValueError('Official detailed constituency page mapping is incomplete')
    return {code: (page, name, text[end:starts[index + 1][3] if index + 1 < len(starts) else len(text)])
            for index, (code, page, name, _, end) in enumerate(starts)}


def verified_result(summary_text: str, detail: str, record: dict) -> dict:
    if (record.get('number_of_seats', 1) != 1 or len(record['candidates']) < 2
            or any(not isinstance(candidate.get('votes'), int) or candidate['votes'] < 0
                   or not candidate.get('candidate_name') or not candidate.get('party_at_election')
                   or not candidate.get('sex') for candidate in record['candidates'])):
        raise ValueError('Detailed candidate row is incomplete: ' + str(record['code']))
    totals = re.search(r'ELECTORS\s*:\s*(\d+)\s+VOTERS\s*:\s*(\d+).*?VALID VOTES\s*:\s*(\d+)',
                       detail, re.I | re.S)
    if not totals or tuple(map(int, totals.groups())) != (record['electors'], record['votes_polled'],
                                                          record['valid_candidate_votes']):
        raise ValueError('Official detailed totals differ: ' + str(record['code']))
    if sum(candidate['votes'] for candidate in record['candidates']) != record['valid_candidate_votes']:
        raise ValueError('Detailed candidate votes do not reconcile: ' + str(record['code']))
    for candidate in record['candidates']:
        pattern = (re.escape(candidate['candidate_name']).replace(r'\ ', r'\s+')
                   + r'\s+' + re.escape(candidate['sex'])
                   + r'\s+' + re.escape(candidate['party_at_election'])
                   + r'\s+' + str(candidate['votes']) + r'(?=\s|$)')
        if not re.search(pattern, detail, re.I):
            raise ValueError('Official detailed candidate row differs: ' + str(record['code']))
    ranked = sorted(record['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    winner, runner = ranked[:2]
    if winner['votes'] <= runner['votes']:
        raise ValueError('Official candidate votes have no unique leader: ' + str(record['code']))
    for label, candidate in (('Winner', winner), ('Runner up', runner)):
        row = re.search(r'^\s*' + label + r'\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$',
                        summary_text, re.I | re.M)
        if (not row or row[1].upper() != candidate['party_at_election'].upper()
                or normalized(row[2]) != normalized(candidate['candidate_name'])
                or int(row[3]) != candidate['votes']):
            raise ValueError('Official summary winner or runner differs: ' + str(record['code']))
    margin = re.search(r'MARGIN\s*:\s*(\d+)', summary_text, re.I)
    if not margin or int(margin[1]) != winner['votes'] - runner['votes']:
        raise ValueError('Official summary margin differs: ' + str(record['code']))
    return {'winner': winner['candidate_name'], 'winner_party': winner['party_at_election'],
            'winner_votes': winner['votes'], 'runner': runner['candidate_name'],
            'runner_party': runner['party_at_election'], 'runner_votes': runner['votes'],
            'margin': int(margin[1])}


def build(root: Path) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body = previous_body(root)
    old_sha = digest(old_body)
    audit_sha, targets = audited_targets(root)
    if old_sha != audit_sha:
        raise ValueError('Prior JSON does not match the last live election audit')
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source = next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)
    source_path = folder / SOURCE_FILE
    if (old['kind'] != 'ac' or old['year'] != 1985 or old['source_url'] != manifest['url']
            or old['source_file'] != SOURCE_FILE or old['source_sha256'] != source['sha256']
            or not source_path.is_file() or source_path.is_symlink()
            or digest(source_path.read_bytes()) != source['sha256']):
        raise ValueError('Official Bihar 1985 source identity or PDF checksum differs')
    summaries = read_summary_pages(source_path)
    if len(summaries) != len(old['records']) or set(summaries) != {row['code'] for row in old['records']}:
        raise ValueError('Official Bihar 1985 summary coverage is incomplete')
    revised = json.loads(old_body)
    results = []
    with fitz.open(source_path) as pdf:
        details = detail_sections(pdf)
        for record in revised['records']:
            code = record['code']
            if code not in targets:
                continue
            if (record['status'] != 'needs_review'
                    or record['error'] != ('Candidate rows transcribed from the detailed PDF; independent summary '
                                           'reconciliation is pending.; Detailed report pages are missing, duplicated or out of order.')
                    or record.get('summary_page') is not None or record.get('summary_totals') is not None
                    or record.get('source_warning_code') is not None
                    or record.get('winner') is not None or record.get('margin') is not None
                    or record['detail_page'] != details[code][0]):
                raise ValueError('Archived Bihar 1985 record differs: ' + str(code))
            summary = summaries[code]
            page, detail_name, section = details[code]
            if normalized(detail_name) != normalized(record['name']) or not corroborates(record, summary):
                raise ValueError('Official Bihar 1985 constituency totals differ: ' + str(code))
            result = verified_result(pdf[summary['summary_page'] - 1].get_text(sort=True), section, record)
            record['original_extraction_warning'] = record['error']
            record['error'] = RECONCILED_NOTE
            record['summary_page'] = summary['summary_page']
            record['summary_totals'] = {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
            record['summary_result'] = result
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = source['sha256']
            record['detail_source_file'] = SOURCE_FILE
            record['detail_source_sha256'] = source['sha256']
            results.append({'code': code, 'name': record['name'], 'detail_page': page,
                            'summary_page': summary['summary_page'], 'winner': result['winner'],
                            'margin': result['margin']})
    if len(results) != EXPECTED_TARGETS or {row['code'] for row in results} != targets:
        raise ValueError('Bihar 1985 source-backed target coverage differs')
    allowed = {'original_extraction_warning', 'error', 'summary_page', 'summary_totals',
               'summary_result', 'summary_source_file', 'summary_source_sha256',
               'detail_source_file', 'detail_source_sha256'}
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (allowed if before['code'] in targets else set()):
            raise ValueError('An unrelated Bihar 1985 election value changed: ' + str(before['code']))
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha = digest(new_body)
    with tempfile.TemporaryDirectory(prefix='ac-bihar-1985-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{old_sha}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        for relative, body in ((snapshot, old_body), (revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
        inner = []
        for kind, relative in (('snapshot', snapshot), ('correction', revision)):
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    old_sha if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({
                'scope': 'Bihar 1985 AC results reconciled to official summary and detail pages',
                'edition': EDITION, 'source_url': old['source_url'], 'source_file': SOURCE_FILE,
                'source_sha256': source['sha256'], 'previous_sha256': old_sha,
                'new_sha256': new_sha, 'records_reconciled': len(results), 'records_unchanged': 1,
                'records': results,
            }, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'reconciled': len(results), 'previous_sha256': old_sha,
            'new_sha256': new_sha, 'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
