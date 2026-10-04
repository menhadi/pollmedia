"""Preserve the two printed winner spellings for Madras AC 1962 code 67."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_ac_1962_madras_reconciled_results import (
    EDITION, ROOT, SOURCE_FILE, SOURCE_SHA, SOURCE_URL, PREVIOUS_ERROR,
    digest, row_total,
)
from build_ac_1962_rajasthan_reconciled_one_seat_results import section, total, norm
from build_pc_1951_multi_seat_declared_members import multi_seat_import_script
from preserve_archive_json import package


NAME = 'pollmedia-ac-1962-madras-uddanapalli-name-review-20261005'
PREDECESSOR = 'f136d896209bae30bc249a1b12cbeca93d7353b2eb3143833a3402e869c5c9a6'
PRIOR_RELEASE = 'pollmedia-ac-1962-madras-205-reconciled-results-20261005'
PRIOR_RELEASE_SHA = 'aad555e4bc86f946710941c6333008dd5f155f10634ce78a2e69197259ef25fc'
CODE = 67
SUMMARY_WINNER = 'CHINNA MUNISAMY CHETTIAR N. MUNISAMY'
DETAIL_WINNER = 'CHINNA MUNISAMY CHETTIAR N. MUNISAMY CHETTY'


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    pdf_path = folder / SOURCE_FILE
    prior_path = root / 'exports' / (PRIOR_RELEASE + '.zip')
    if (pdf_path.is_symlink() or digest(pdf_path.read_bytes()) != SOURCE_SHA
            or prior_path.is_symlink() or digest(prior_path.read_bytes()) != PRIOR_RELEASE_SHA):
        raise ValueError('1962 Madras source PDF or queued predecessor differs')
    with zipfile.ZipFile(prior_path) as release:
        with zipfile.ZipFile(io.BytesIO(release.read(f'correction-{EDITION}.zip'))) as prior:
            old_body = prior.read(f'election-archive/{EDITION}/extraction.json')
    if digest(old_body) != PREDECESSOR:
        raise ValueError('1962 Madras queued extraction bytes differ')
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (before['kind'] != 'ac' or before['year'] != 1962 or before['source_url'] != SOURCE_URL
            or before['source_file'] != SOURCE_FILE or before['source_sha256'] != SOURCE_SHA
            or len(before['records']) != 206):
        raise ValueError('1962 Madras archive provenance differs')
    row = after['records'][CODE - 1]
    if (row['code'] != CODE or row['name'] != 'UDDANAPALLI' or row['state_name'] != 'Madras'
            or row['number_of_seats'] != 1 or row['status'] != 'needs_review'
            or row['error'] != PREVIOUS_ERROR or row.get('summary_page') is not None
            or row.get('source_warning_code') is not None or row['detail_page'] != 231):
        raise ValueError('1962 Madras code 67 archive identity differs')
    page = CODE + 15
    with fitz.open(pdf_path) as pdf:
        text = pdf[page - 1].get_text(sort=True)
        detail = pdf[row['detail_page'] - 1].get_text(sort=True)
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != CODE or norm(identity[2]) != norm(row['name'])
            or 'LEGISLATIVE ASSEMBLY OF MADRAS' not in text.upper()
            or re.search(r'Constituency\s+' + str(CODE) + r'\s+UDDANAPALLI\b', detail) is None):
        raise ValueError('1962 Madras code 67 official identity differs')
    electors = total(section(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED'))
    voters = total(section(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES'))
    votes = section(text, r'IV\. VOTES', r'V\. POLLING STATIONS')
    valid = row_total(votes, 2, 'VALID')
    rejected = row_total(votes, 3, 'REJECTED')
    missing = row_total(votes, 4, 'MISSING')
    result = section(text, r'VII\. RESULT', r'rptConstituencySummary')
    declared = re.findall(r'^[ \t]*(Winner|Runner up)[ \t]*:?[ \t]+(\S+)[ \t]+(.+?)[ \t]+(\d+)[ \t]*$', result, re.I | re.M)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', result, re.I)
    ranked = sorted(row['candidates'], key=lambda candidate: candidate['votes'], reverse=True)
    if (electors != row['electors'] or voters != row_total(votes, 1, 'POLLED')
            or voters != row['votes_polled'] + missing or valid != row['valid_candidate_votes']
            or valid + rejected == 0 or valid + rejected + missing != voters
            or sum(candidate['votes'] for candidate in ranked) != valid
            or len(declared) != 2 or margin is None or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']
            or (declared[0][2].strip(), declared[0][1], int(declared[0][3])) != (SUMMARY_WINNER, ranked[0]['party_at_election'], ranked[0]['votes'])
            or (ranked[0]['candidate_name'], declared[1][2].strip(), declared[1][1], int(declared[1][3])) !=
            (DETAIL_WINNER, ranked[1]['candidate_name'], ranked[1]['party_at_election'], ranked[1]['votes'])
            or DETAIL_WINNER not in detail or missing <= 0 or missing * 1000 > voters * 2):
        raise ValueError('1962 Madras code 67 official figures or spellings differ')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Madras'
    row['source_warning_code'] = 'official_summary_turnout_only'
    row['source_discrepancy'] = {'field': 'votes_polled', 'detail_value': row['votes_polled'],
                                 'summary_value': voters, 'valid_detail_value': valid,
                                 'valid_summary_value': valid, 'missing_votes': missing}
    row['summary_totals'] = {'electors': electors, 'votes_polled': voters,
                             'valid_candidate_votes': valid}
    row['summary_result'] = {'winner': SUMMARY_WINNER, 'winner_party': ranked[0]['party_at_election'],
                             'winner_votes': ranked[0]['votes'], 'runner': ranked[1]['candidate_name'],
                             'runner_party': ranked[1]['party_at_election'],
                             'runner_votes': ranked[1]['votes'], 'margin': int(margin[1])}
    row['source_name_discrepancy'] = {'field': 'winner', 'summary_value': SUMMARY_WINNER,
                                      'detail_value': DETAIL_WINNER}
    row['error'] = (f'Official summary declares {SUMMARY_WINNER}; the detailed candidate row spells '
                    f'{DETAIL_WINNER}. Both show SWA and {ranked[0]["votes"]} votes, with margin '
                    f'{margin[1]}. Summary voters {voters} exceed detailed VOTERS {row["votes_polled"]} '
                    f'by the printed {missing} MISSING votes. Preserve both name and turnout readings for review.')
    common = {'previous_review_note', 'original_extraction_warning', 'summary_page',
              'summary_source_file', 'summary_source_sha256', 'detail_source_file',
              'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
              'official_summary_state', 'source_warning_code', 'source_discrepancy',
              'summary_totals', 'summary_result', 'source_name_discrepancy', 'error'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if (old['candidates'] != new['candidates'] or changed != (common if old['code'] == CODE else set())):
            raise ValueError(f'Unrelated 1962 Madras evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1962-madras-name-', dir=root / 'exports') as temporary:
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
                'scope': '1962 Madras AC UDDANAPALLI declared winner spelling and turnout review',
                'edition': EDITION, 'code': CODE, 'source_url': SOURCE_URL,
                'source_file': SOURCE_FILE, 'source_sha256': SOURCE_SHA,
                'previous_sha256': PREDECESSOR, 'new_sha256': digest(new_body),
                'summary_page': page, 'detail_page': row['detail_page'],
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'name_review': 1}


if __name__ == '__main__':
    print(json.dumps(build()))
