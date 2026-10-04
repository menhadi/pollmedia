"""Keep printed summary and detailed runner names distinct in 1962 Mysore."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_ac_1962_madras_reconciled_results import row_total
from build_ac_1962_mysore_166_reconciled_results import (
    ROOT, EDITION, SOURCE_FILE, SOURCE_SHA, SOURCE_URL, PREVIOUS_ERROR,
    digest,
)
from build_ac_1962_rajasthan_reconciled_one_seat_results import norm, section, total
from build_pc_1951_multi_seat_declared_members import multi_seat_import_script
from preserve_archive_json import package


NAME = 'pollmedia-ac-1962-mysore-three-name-review-results-20261005'
PREDECESSOR = '1a6016d296a3b39f13fe408738f0391656dda2f44f820e2296f2e282bb736ff1'
PRIOR_RELEASE = 'pollmedia-ac-1962-mysore-37-voter-discrepancy-results-20261005'
PRIOR_RELEASE_SHA = '6b1fe532da6a802e99a6cb20d64c39536acb6211439b11d4a9b4da7ed6fbde53'
NAMES = {
    22: ('GAIGAYYA PADADAPPAYYA NANJAYANMATH', 'GAIGAYYA PADADAPPAYYA NANJAYANMATH ALIAS PARAYYA'),
    38: ('SHANKARRAO DADASAHEB KOTHAVAL ALIAS', 'SHANKARRAO DADASAHEB KOTHAVAL ALIAS DADOBA'),
    58: ('SHIDDANAGOUDA SHIVABASANAGOUDA', 'SHIDDANAGOUDA SHIVABASANAGOUDA KAREGOUDAR'),
}


def review(row: dict, page: int, text: str) -> None:
    code = row['code']
    printed, detailed = NAMES[code]
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
    if (identity is None or int(identity[1]) != code or norm(identity[2]) != norm(row['name'])
            or 'LEGISLATIVE ASSEMBLY OF MYSORE' not in text.upper()
            or 'CONSTITUENCY DATA - SUMMARY' not in text
            or row['state_name'] != 'Karnataka' or row['number_of_seats'] != 1
            or row['status'] != 'needs_review' or row['error'] != PREVIOUS_ERROR
            or row.get('summary_page') is not None or row.get('source_warning_code') is not None):
        raise ValueError(f'1962 Mysore name-review identity differs: {code}')
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
    if (electors != row['electors'] or voters != row['votes_polled'] or voters != row_total(votes, 1, 'POLLED')
            or not (0 < valid <= voters <= electors) or valid != row['valid_candidate_votes']
            or valid + rejected != voters or missing != 0
            or sum(candidate['votes'] for candidate in ranked) != valid
            or len(declared) != 2 or [part[0].lower() for part in declared] != ['winner', 'runner up']
            or margin is None or int(margin[1]) != ranked[0]['votes'] - ranked[1]['votes']
            or (declared[0][2].strip(), declared[0][1], int(declared[0][3])) !=
            (ranked[0]['candidate_name'], ranked[0]['party_at_election'], ranked[0]['votes'])
            or (declared[1][2].strip(), declared[1][1], int(declared[1][3])) !=
            (printed, ranked[1]['party_at_election'], ranked[1]['votes'])
            or ranked[1]['candidate_name'] != detailed):
        raise ValueError(f'1962 Mysore name-review source figures differ: {code}')
    row['previous_review_note'] = row['error']
    row['original_extraction_warning'] = row['error']
    row['summary_page'] = page
    row['summary_source_file'] = SOURCE_FILE
    row['summary_source_sha256'] = SOURCE_SHA
    row['detail_source_file'] = SOURCE_FILE
    row['detail_source_sha256'] = SOURCE_SHA
    row['official_source_url'] = SOURCE_URL
    row['official_summary_constituency_name'] = identity[2].strip()
    row['official_summary_state'] = 'Mysore'
    row['source_warning_code'] = 'official_summary_turnout_only'
    row['source_name_discrepancy'] = {'field': 'runner', 'summary_value': printed,
                                      'detail_value': detailed}
    row['summary_totals'] = {'electors': electors, 'votes_polled': voters,
                             'valid_candidate_votes': valid}
    row['summary_result'] = {'winner': ranked[0]['candidate_name'],
                             'winner_party': ranked[0]['party_at_election'],
                             'winner_votes': ranked[0]['votes'], 'runner': printed,
                             'runner_party': ranked[1]['party_at_election'],
                             'runner_votes': ranked[1]['votes'], 'margin': int(margin[1])}
    row['error'] = (f'Official summary prints runner-up {printed}; detailed candidate row prints '
                    f'{detailed}. Party and {ranked[1]["votes"]} votes agree, as do winner, turnout '
                    'and margin. Both printed names remain for review.')


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    prior_release = root / 'exports' / (PRIOR_RELEASE + '.zip')
    if (source.is_symlink() or digest(source.read_bytes()) != SOURCE_SHA
            or prior_release.is_symlink() or digest(prior_release.read_bytes()) != PRIOR_RELEASE_SHA):
        raise ValueError('1962 Mysore queued predecessor or source PDF differs')
    with zipfile.ZipFile(prior_release) as release:
        with zipfile.ZipFile(io.BytesIO(release.read(f'correction-{EDITION}.zip'))) as prior:
            old_body = prior.read(f'election-archive/{EDITION}/extraction.json')
    if digest(old_body) != PREDECESSOR:
        raise ValueError('1962 Mysore queued extraction bytes differ')
    before = json.loads(old_body)
    after = json.loads(old_body)
    if (before['kind'] != 'ac' or before['year'] != 1962 or len(before['records']) != 208
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA):
        raise ValueError('1962 Mysore archive provenance differs')
    with fitz.open(source) as pdf:
        for code in NAMES:
            review(after['records'][code - 1], code + 15, pdf[code + 14].get_text(sort=True))
    expected = {'previous_review_note', 'original_extraction_warning', 'summary_page',
                'summary_source_file', 'summary_source_sha256', 'detail_source_file',
                'detail_source_sha256', 'official_source_url', 'official_summary_constituency_name',
                'official_summary_state', 'source_warning_code', 'source_name_discrepancy',
                'summary_totals', 'summary_result', 'error'}
    for old, new in zip(before['records'], after['records'], strict=True):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['candidates'] != new['candidates'] or changed != (expected if old['code'] in NAMES else set()):
            raise ValueError(f'Unrelated 1962 Mysore evidence changed: {old["code"]}')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    snapshot = f'election-archive/{EDITION}/extraction-{PREDECESSOR}.json'
    revision = f'election-archive/{EDITION}/extraction.json'
    with tempfile.TemporaryDirectory(prefix='ac-1962-mysore-names-', dir=root / 'exports') as temporary:
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
                'scope': 'three printed runner-name differences in 1962 Mysore AC summaries',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'previous_sha256': PREDECESSOR,
                'new_sha256': digest(new_body), 'codes': sorted(NAMES),
            }, indent=2))
            release.writestr('IMPORT.sh', multi_seat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': PREDECESSOR,
            'new_sha256': digest(new_body), 'name_reviews': len(NAMES)}


if __name__ == '__main__':
    print(json.dumps(build()))
