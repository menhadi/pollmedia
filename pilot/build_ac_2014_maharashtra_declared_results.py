"""Expose ten source-backed Maharashtra 2014 AC results with prior warnings intact."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


EDITION = 'c590e168b33b5fb8f213d092'
PRIOR_BUNDLE = 'pollmedia-ac-residual-turnout-corrections-20261001-v4.zip'
NAME = 'pollmedia-ac-maharashtra-2014-declared-results-20261003'
PRIOR_SHA256 = '3ea169453c37d70f5cb2baecc714b6eac6d399bba4f5299c3e43cf9d9629d87e'
SOURCE_SHA256 = 'c894abd9749c34aa42504b0a726f823353c6f6063ba59056af3b2c9bca400d32'
SUMMARY_CODES = {28, 40, 265}
DETAIL_CODES = {193, 228, 229, 230, 231, 232, 233}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(name: str) -> str:
    return re.sub(r'[^a-z0-9]', '', name.casefold())


def declared_summary(record: dict, text: str) -> tuple[dict, str | None]:
    code = record['code']
    heading = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text)
    winner = re.search(r'^WINNER\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.M)
    runner = re.search(r'^RUNNER-UP\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.M)
    margin = re.search(r'^MARGIN\s+(\d+)\b', text, re.M)
    valid = re.search(r'^\s*7\.TOTAL VALID VOTES POLLED\s+(\d+)\s*$', text, re.M)
    if (not all((heading, winner, runner, margin, valid))
            or int(heading[1]) != code or norm(heading[2]) != norm(record['name'])
            or record['status'] != 'needs_review' or record['number_of_seats'] != 1
            or record['source_warning_code'] != 'official_summary_turnout_only'
            or record.get('summary_result') is not None):
        raise ValueError(f'Official Maharashtra summary identity differs: {code}')
    first, second = int(winner[3]), int(runner[3])
    if not 0 < second < first <= int(valid[1]) or first - second != int(margin[1]):
        raise ValueError(f'Official Maharashtra summary margin differs: {code}')
    candidates = sorted((c for c in record['candidates'] if not c['is_nota']), key=lambda c: -c['votes'])
    if (len(candidates) < 2 or [c['votes'] for c in candidates[:2]] != [first, second]
            or [c['party_at_election'] for c in candidates[:2]] != [winner[1], runner[1]]
            or [norm(c['candidate_name']) for c in candidates[:2]] != [norm(winner[2]), norm(runner[2])]):
        raise ValueError(f'Official Maharashtra summary candidate rows differ: {code}')
    result = {'winner': winner[2], 'winner_party': winner[1], 'winner_votes': first,
              'runner': runner[2], 'runner_party': runner[1], 'runner_votes': second,
              'margin': int(margin[1])}
    note = None
    if int(valid[1]) != record['valid_candidate_votes']:
        note = (f' Official summary prints {int(valid[1]):,} valid votes; preserved detailed '
                f'candidate rows total {record["valid_candidate_votes"]:,}.')
    return result, note


def declared_detail(record: dict) -> dict:
    code = record['code']
    candidates = record['candidates']
    pages = sorted({c['source_page'] for c in candidates})
    source_page = record['detail_page']
    turnout_page = record['turnout_source_page']
    if (record['status'] != 'needs_review' or record['number_of_seats'] != 1
            or record['source_warning_code'] != 'official_turnout_from_residual_source'
            or record.get('official_detail_result') is not None or len(candidates) < 2
            or source_page != pages[0] or turnout_page not in (source_page, source_page + 1)
            or pages not in ([source_page], [source_page, source_page + 1])
            or [c['source_row'] for c in candidates] != list(range(1, len(candidates) + 1))
            or len({(c['candidate_name'].casefold(), c['party_at_election'].casefold()) for c in candidates}) != len(candidates)
            or any(c['votes'] != c['general_votes'] + c['postal_votes'] for c in candidates)
            or sum(c['votes'] for c in candidates) != record['votes_polled']
            or sum(c['votes'] for c in candidates if not c['is_nota']) != record['valid_candidate_votes']
            or sum(c['general_votes'] for c in candidates) != record['turnout_totals']['general_votes']
            or sum(c['postal_votes'] for c in candidates) != record['turnout_totals']['postal_votes']):
        raise ValueError(f'Official Maharashtra detailed result cannot be reconciled: {code}')
    ranked = sorted((c for c in candidates if not c['is_nota']), key=lambda c: -c['votes'])
    if ranked[0]['votes'] <= ranked[1]['votes']:
        raise ValueError(f'Official Maharashtra detailed result is tied: {code}')
    result = {'source_file': record['turnout_source_file'],
              'source_sha256': record['turnout_source_sha256'], 'source_page': source_page,
              'winner': ranked[0]['candidate_name'], 'winner_party': ranked[0]['party_at_election'],
              'winner_votes': ranked[0]['votes'], 'runner': ranked[1]['candidate_name'],
              'runner_party': ranked[1]['party_at_election'], 'runner_votes': ranked[1]['votes'],
              'margin': ranked[0]['votes'] - ranked[1]['votes']}
    if turnout_page != source_page:
        result['source_pages'] = [source_page, turnout_page]
    return result


def build(root: Path) -> dict:
    exports = root / 'exports'
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    prior_path = exports / PRIOR_BUNDLE
    if prior_path.with_suffix('.sha256').read_text(encoding='ascii').split() != [digest(prior_path.read_bytes()), prior_path.name]:
        raise ValueError('Prior Maharashtra correction bundle checksum differs')
    with zipfile.ZipFile(prior_path) as outer:
        audit = json.loads(outer.read('AUDIT.json'))
        audited = next(e for e in audit['editions'] if e['edition'] == EDITION)
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            old_body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if audited['new_sha256'] != PRIOR_SHA256 or digest(old_body) != PRIOR_SHA256:
        raise ValueError('Prior Maharashtra correction body differs')
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_path = folder / old['source_file']
    if (old['kind'] != 'ac' or old['year'] != 2014 or len(old['records']) != 288
            or old['source_url'] != manifest['url'] or old['source_sha256'] != SOURCE_SHA256
            or digest(source_path.read_bytes()) != SOURCE_SHA256):
        raise ValueError('Official Maharashtra source identity differs')
    revised = json.loads(old_body)
    results = []
    with fitz.open(source_path) as pdf:
        for record in revised['records']:
            code = record['code']
            if code in SUMMARY_CODES:
                result, note = declared_summary(record, pdf[record['summary_page'] - 1].get_text(sort=True))
                record['summary_result'] = result
                if note:
                    record['error'] += note
            elif code in DETAIL_CODES:
                result = declared_detail(record)
                record['official_detail_result'] = result
            else:
                continue
            results.append({'code': code, 'name': record['name'], 'winner': result['winner'],
                            'margin': result['margin'], 'source_page': record.get('summary_page') or record['detail_page']})
    if len(results) != 10 or {r['code'] for r in results} != SUMMARY_CODES | DETAIL_CODES:
        raise ValueError('Maharashtra target coverage differs')
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        expected = ({'summary_result'} | ({'error'} if before['code'] in SUMMARY_CODES
                                           and before['valid_candidate_votes'] != {28: 164976, 40: 181942, 265: 165545}[before['code']]
                                           else set()) if before['code'] in SUMMARY_CODES else
                    {'official_detail_result'} if before['code'] in DETAIL_CODES else set())
        if before['code'] != after['code'] or changed != expected:
            raise ValueError(f'Unrelated Maharashtra record changed: {before["code"]}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    with tempfile.TemporaryDirectory(prefix='ac-maharashtra-2014-', dir=exports) as temporary:
        staging = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{PRIOR_SHA256}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        for relative, body in ((snapshot, old_body), (revision, new_body)):
            target = staging / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
        inner = []
        for kind, relative in (('snapshot', snapshot), ('correction', revision)):
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staging, path, 'election-archive', bucket, 8, [relative],
                    PRIOR_SHA256 if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for path in inner:
                zipped.write(path, path.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            zipped.writestr('ARCHIVES', EDITION + '\n')
            zipped.writestr('AUDIT.json', json.dumps({
                'scope': 'Maharashtra 2014 AC source-backed summary and reconciled detailed results',
                'edition': EDITION, 'source_url': old['source_url'], 'source_file': old['source_file'],
                'source_sha256': SOURCE_SHA256, 'previous_sha256': PRIOR_SHA256,
                'new_sha256': digest(new_body), 'results': results,
            }, indent=2))
            zipped.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_text(f'{digest(output.read_bytes())}  {output.name}\n', encoding='ascii')
    return {'bundle': str(output), 'results': len(results), 'previous_sha256': PRIOR_SHA256,
            'new_sha256': digest(new_body), 'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
