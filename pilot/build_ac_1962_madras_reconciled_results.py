"""Review Madras 1962 AC declarations against the archived official report."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_ac_1962_rajasthan_reconciled_one_seat_results import norm, section, total
from build_pc_1951_multi_seat_declared_members import multi_seat_import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'd63d001237a6e1be0c4bfcb2'
NAME = 'pollmedia-ac-1962-madras-205-reconciled-results-20261005'
PREDECESSOR = '13200adcd43d57cea28972b254c5ddc8a5a96e2b5ebea863e8094cd7209607e3'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4102-madras-1962/'
SOURCE_FILE = f'{EDITION}-9716.pdf'
SOURCE_SHA = 'e569acec39892b081ece6fdc305337983f2dbbd2c76485a6437dd558b0eed694'
PREVIOUS_ERROR = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
WITHHELD_CODE = 67  # UDDANAPALLI: winner name differs between summary and detailed candidate row.


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def row_total(text: str, ordinal: int, label: str) -> int:
    match = re.search(r'(?m)^[ \t]*' + str(ordinal) + r'\. ' + label + r'[ \t]+(\d+)', text, re.I)
    if match is None:
        raise ValueError(f'Official Madras summary lacks {label}')
    return int(match[1])


def reconcile(record: dict, page: int, text: str) -> str:
    code = record['code']
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code or norm(identity[2]) != norm(record['name'])
            or 'CONSTITUENCY DATA - SUMMARY' not in text
            or 'LEGISLATIVE ASSEMBLY OF MADRAS' not in text.upper()
            or record['number_of_seats'] != 1 or record['state_name'] != 'Madras'
            or record['status'] != 'needs_review' or record['error'] != PREVIOUS_ERROR
            or record.get('summary_page') is not None or record.get('source_warning_code') is not None
            or len(record['candidates']) < 2 or 'Uncontested' in text):
        raise ValueError(f'1962 Madras constituency identity differs: {code}')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters = total(section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES'))
    votes = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    polled = row_total(votes, 1, 'POLLED')
    valid = row_total(votes, 2, 'VALID')
    rejected = row_total(votes, 3, 'REJECTED')
    missing = row_total(votes, 4, 'MISSING')
    result = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    declared = re.findall(r'^[ \t]*(Winner|Runner up)[ \t]*:?[ \t]+(\S+)[ \t]+(.+?)[ \t]+(\d+)[ \t]*$', result, re.I | re.M)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', result, re.I)
    ranked = sorted(record['candidates'], key=lambda row: row['votes'], reverse=True)
    if (electors != record['electors'] or not (0 < voters <= electors)
            or voters != polled or valid != record['valid_candidate_votes']
            or valid + rejected + missing != voters
            or valid + rejected != record['votes_polled']
            or sum(row['votes'] for row in ranked) != valid
            or len({(row['candidate_name'], row['party_at_election'], row['votes']) for row in ranked}) != len(ranked)
            or len(declared) != 2 or [row[0].lower() for row in declared] != ['winner', 'runner up']
            or margin is None or ranked[0]['votes'] <= ranked[1]['votes']
            or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']):
        raise ValueError(f'1962 Madras totals or margin differ: {code}')
    for source, candidate in zip(declared, ranked[:2], strict=True):
        if (source[2].strip(), source[1], int(source[3])) != (
                candidate['candidate_name'], candidate['party_at_election'], candidate['votes']):
            raise ValueError(f'1962 Madras declared candidate differs: {code}')
    record['previous_review_note'] = record['error']
    record['original_extraction_warning'] = record['error']
    record['summary_page'] = page
    record['summary_source_file'] = SOURCE_FILE
    record['summary_source_sha256'] = SOURCE_SHA
    record['detail_source_file'] = SOURCE_FILE
    record['detail_source_sha256'] = SOURCE_SHA
    record['official_source_url'] = SOURCE_URL
    record['official_summary_constituency_name'] = identity[2].strip()
    record['official_summary_state'] = 'Madras'
    record['source_warning_code'] = 'official_summary_turnout_only'
    record['summary_totals'] = {'electors': electors, 'votes_polled': voters,
                                'valid_candidate_votes': valid}
    record['summary_result'] = {'winner': ranked[0]['candidate_name'],
                                'winner_party': ranked[0]['party_at_election'],
                                'winner_votes': ranked[0]['votes'],
                                'runner': ranked[1]['candidate_name'],
                                'runner_party': ranked[1]['party_at_election'],
                                'runner_votes': ranked[1]['votes'], 'margin': int(margin[1])}
    if missing:
        if missing * 1000 > voters * 2:
            raise ValueError(f'1962 Madras unexplained turnout gap: {code}')
        record['source_discrepancy'] = {
            'field': 'votes_polled', 'detail_value': record['votes_polled'],
            'summary_value': voters, 'valid_detail_value': valid,
            'valid_summary_value': valid, 'missing_votes': missing,
        }
        record['error'] = (f'Official summary reports {voters} electors who voted; detailed VOTERS row reports '
                           f'{record["votes_polled"]}. The {missing}-vote difference equals the summary MISSING '
                           'entry. Winner and margin agree; both turnout figures remain for review.')
        return 'printed-missing-votes'
    record['error'] = ('Official Madras summary corroborates turnout, named winner, runner-up and margin; '
                       'original detailed-PDF extraction warning remains for review.')
    return 'reconciled'


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    old_body = (folder / 'extraction.json').read_bytes()
    if digest(old_body) != PREDECESSOR or source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA:
        raise ValueError('1962 Madras precursor or official PDF differs')
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (manifest['url'] != SOURCE_URL
            or next(file for file in manifest['files'] if file['file'] == SOURCE_FILE)['sha256'] != SOURCE_SHA
            or before['kind'] != 'ac' or before['year'] != 1962
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA or len(before['records']) != 206):
        raise ValueError('1962 Madras archive provenance differs')
    seen = []
    with fitz.open(source) as pdf:
        if len(pdf) != 250:
            raise ValueError('1962 Madras official PDF page count differs')
        for record in after['records']:
            code = record['code']
            if code == WITHHELD_CODE:
                continue
            page = code + 15
            kind = reconcile(record, page, pdf[page - 1].get_text(sort=True))
            seen.append({'code': code, 'summary_page': page, 'review': kind})
    if (len(seen) != 205 or [item['code'] for item in seen] != [c for c in range(1, 207) if c != WITHHELD_CODE]
            or sum(item['review'] == 'printed-missing-votes' for item in seen) != 189):
        raise ValueError('1962 Madras review counts differ')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'source_warning_code', 'summary_totals',
              'summary_result', 'error'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = common | ({'source_discrepancy'} if new.get('source_discrepancy') else set())
        if (old['code'] != new['code'] or old['candidates'] != new['candidates']
                or changed != (set() if old['code'] == WITHHELD_CODE else expected)):
            raise ValueError(f'Unrelated 1962 Madras evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1962-madras-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        archives = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            archive = packages / f'{kind}-{EDITION}.zip'
            package(staged, archive, 'election-archive', bucket, 8, [relative],
                    PREDECESSOR if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            archives.append(archive)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as release:
            for archive in archives:
                release.write(archive, archive.name)
            release.writestr('SHA256SUMS', ''.join(f'{digest(archive.read_bytes())}  {archive.name}\n'
                                                for archive in archives))
            release.writestr('ARCHIVES', EDITION + '\n')
            release.writestr('AUDIT.json', json.dumps({
                'scope': '205 source-reconciled 1962 Madras AC one-seat declarations',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'results': seen,
                'withheld_name_difference_code': WITHHELD_CODE,
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'reviewed': 205,
            'printed_missing_votes': 189, 'withheld_name_difference': 1}


if __name__ == '__main__':
    print(json.dumps(build()))
