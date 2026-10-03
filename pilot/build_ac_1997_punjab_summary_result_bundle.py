"""Preserve incomplete detailed rows while exposing Punjab 1997 official summaries."""

import csv
import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1992_summary_result_bundle import normalized
from build_pc_ac_zero_turnout_bundle import import_script
from extract_assembly_summary_totals import read_summary_pages
from preserve_archive_json import package


EDITION = '9cd3af195ef2e783313b2fce'
SOURCE_FILE = EDITION + '-7994.pdf'
NAME = 'pollmedia-ac-punjab-1997-official-summary-results-20261003'
OLD_SHA256 = '2dacb71f082e9f17b087b79f1b1e9b21dc2e678ee7a88c5cc5cceee104cfe1e3'
EXPECTED = 45
NOTE = ('Official summary reports turnout, winner and margin; detailed candidate rows are incomplete '
        'and remain under review.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def audited_codes(root: Path) -> set[int]:
    with (root / 'exports/pc-ac-display-audit-after-bdd282d.csv').open(encoding='utf-8-sig', newline='') as source:
        rows = [row for row in csv.DictReader(source)
                if row['edition_id'] == EDITION and row['issue'] == 'source_turnout_hidden']
    codes = {int(row['code']) for row in rows}
    if len(rows) != EXPECTED or len(codes) != EXPECTED or {row['extraction_sha256'] for row in rows} != {OLD_SHA256}:
        raise ValueError('Last live Punjab 1997 turnout audit differs')
    return codes


def verified_summary(record: dict, summary: dict, page_text: str) -> dict:
    code = record['code']
    if (record['number_of_seats'] != 1 or summary['code'] != code
            or normalized(summary['name']) != normalized(record['name'])
            or any(record[field] != summary[field]
                   for field in ('electors', 'votes_polled', 'valid_candidate_votes'))
            or not 0 < summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']):
        raise ValueError('Official Punjab 1997 summary totals differ: ' + str(code))
    rows = re.findall(r'^\s*(Winner|Runner up)\s*:?\s*(\S+)\s+(.+?)\s+(\d+)\s*$',
                      page_text, re.I | re.M)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', page_text, re.I)
    if len(rows) != 2 or not margin or rows[0][0].casefold() != 'winner' or rows[1][0].casefold() != 'runner up':
        raise ValueError('Official Punjab 1997 result rows missing: ' + str(code))
    winner_votes, runner_votes, margin_votes = int(rows[0][3]), int(rows[1][3]), int(margin[1])
    if (not 0 <= runner_votes < winner_votes <= summary['valid_candidate_votes']
            or winner_votes - runner_votes != margin_votes):
        raise ValueError('Official Punjab 1997 margin differs: ' + str(code))
    return {'winner': rows[0][2].strip(), 'winner_party': rows[0][1].strip(),
            'winner_votes': winner_votes, 'runner': rows[1][2].strip(),
            'runner_party': rows[1][1].strip(), 'runner_votes': runner_votes,
            'margin': margin_votes}


def build(root: Path) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source = next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)
    source_path = folder / SOURCE_FILE
    if (digest(old_body) != OLD_SHA256 or old['kind'] != 'ac' or old['year'] != 1997
            or old['source_url'] != manifest['url'] or old['source_file'] != SOURCE_FILE
            or old['source_sha256'] != source['sha256'] or not source_path.is_file()
            or source_path.is_symlink() or digest(source_path.read_bytes()) != source['sha256']):
        raise ValueError('Official Punjab 1997 source identity or checksum differs')
    targets = audited_codes(root)
    summaries = read_summary_pages(source_path)
    if len(summaries) != len(old['records']) or set(summaries) != {row['code'] for row in old['records']}:
        raise ValueError('Official Punjab 1997 summary coverage is incomplete')
    revised = json.loads(old_body)
    results = []
    with fitz.open(source_path) as pdf:
        for record in revised['records']:
            code = record['code']
            if code not in targets:
                continue
            if (record['status'] != 'needs_review'
                    or 'Some candidate text could not be parsed' not in record['error']
                    or 'Extracted candidate votes do not match the reported valid votes' not in record['error']
                    or record.get('summary_totals') is not None
                    or record.get('source_warning_code') is not None
                    or record.get('winner') is not None or record.get('margin') is not None):
                raise ValueError('Archived Punjab 1997 record differs: ' + str(code))
            summary = summaries[code]
            result = verified_summary(record, summary, pdf[summary['summary_page'] - 1].get_text(sort=True))
            record['original_extraction_warning'] = record['error']
            record['error'] = NOTE
            record['source_warning_code'] = 'official_summary_turnout_only'
            record['summary_page'] = summary['summary_page']
            record['summary_totals'] = {field: summary[field] for field in ('electors', 'votes_polled', 'valid_candidate_votes')}
            record['summary_result'] = result
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = source['sha256']
            results.append({'code': code, 'name': record['name'], 'summary_page': summary['summary_page'],
                            'winner': result['winner'], 'margin': result['margin']})
    if len(results) != EXPECTED or {row['code'] for row in results} != targets:
        raise ValueError('Punjab 1997 source-backed target coverage differs')
    allowed = {'original_extraction_warning', 'error', 'source_warning_code', 'summary_page',
               'summary_totals', 'summary_result', 'summary_source_file', 'summary_source_sha256'}
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (allowed if before['code'] in targets else set()):
            raise ValueError('An unrelated Punjab 1997 election value changed: ' + str(before['code']))
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha = digest(new_body)
    with tempfile.TemporaryDirectory(prefix='ac-punjab-1997-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{OLD_SHA256}.json'
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
                    OLD_SHA256 if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({
                'scope': 'Punjab 1997 AC summary results; incomplete detailed candidate rows retained with warning',
                'edition': EDITION, 'source_url': old['source_url'], 'source_file': SOURCE_FILE,
                'source_sha256': source['sha256'], 'previous_sha256': OLD_SHA256,
                'new_sha256': new_sha, 'records_reconciled': len(results),
                'records_unchanged': len(old['records']) - len(results), 'records': results,
            }, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'reconciled': len(results), 'previous_sha256': OLD_SHA256,
            'new_sha256': new_sha, 'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
