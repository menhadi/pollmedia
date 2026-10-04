"""Reconcile three 1951 PEPSU PCs against the archived official Vol II summary."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1957_single_seat_results_bundle import verified_summary
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '9a57af51e71e6ff194d3f409'
NAME = 'pollmedia-pc-1951-pepsu-three-summary-results-20261004'
LIVE_SHA = '12ec5ea1cffefa9f3492d92d07dd44e2c42c2585dcd8b3e2fc5dc555b2fe0b43'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4111-general-election-1951-vol-i-ii/'
DETAIL_FILE = EDITION + '-9734.pdf'
DETAIL_SHA = 'ddc824406b6b49a01b0d9b73394827bd04945772471906d77c76addce5f197e2'
SUMMARY_FILE = EDITION + '-9735.pdf'
SUMMARY_SHA = '5edb3b4145da98e57b4032430cf0fa846a882d22278a41fa491b9a5d14c93718'
OLD_WARNING = ('Matching state/constituency summary is unavailable; '
               'Independent summary totals could not be reconciled')
REVIEW_NOTE = ('Official 1951 Lok Sabha constituency summary confirms turnout, winner and margin. '
               'The summary shortens the PEPSU state label; detailed constituency identity, '
               'candidate rows and both reported vote totals agree. The original name-match '
               'warning is retained for review.')
# global code: (official code, name, summary PDF page, electors, voters, winner votes, runner votes, margin)
TARGETS = {
    342: (1, 'MOHINDERGARH', 346, 333436, 180559, 57290, 36499, 20791),
    343: (2, 'SANGRUR', 347, 349335, 211718, 70569, 56751, 13818),
    344: (3, 'PATIALA', 348, 367485, 219562, 103552, 80948, 22604),
}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def guarded_import_script() -> str:
    script = import_script([EDITION])
    if script.count('exec 9> .import.lock') != 1 or script.count('check_disk\nwhile IFS=') != 1:
        raise ValueError('Election import guard template changed')
    script = script.replace('exec 9> .import.lock',
                            'exec 9> /home/pollmedia/tmp/.pollmedia-election-release.lock')
    script = script.replace('check_disk\nwhile IFS=',
                            "if pgrep -af 'archive:import-json|archive:index-constituencies|[e]lection.*[o]cr'; then\n"
                            "    echo 'Another election import, index, or OCR process is active' >&2\n"
                            "    exit 1\nfi\n"
                            'check_disk\nwhile IFS=')
    return script


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (root / 'tmp/pc-1951-live-20261004.json').read_bytes()
    if digest(old_body) != LIVE_SHA:
        raise ValueError('Live 1951 predecessor checksum differs')
    old = json.loads(old_body)
    revised = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    files = {file['file']: file for file in manifest['files']}
    if (old['kind'] != 'pc' or old['year'] != 1951 or len(old['records']) != 401
            or old['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or old['source_file'] != DETAIL_FILE or old['source_sha256'] != DETAIL_SHA
            or old['additional_sources'][0]['file'] != SUMMARY_FILE
            or old['additional_sources'][0]['sha256'] != SUMMARY_SHA
            or files[DETAIL_FILE]['sha256'] != DETAIL_SHA
            or files[SUMMARY_FILE]['sha256'] != SUMMARY_SHA):
        raise ValueError('Official 1951 Vol I/II identity differs')
    for name, sha in ((DETAIL_FILE, DETAIL_SHA), (SUMMARY_FILE, SUMMARY_SHA)):
        path = folder / name
        if path.is_symlink() or digest(path.read_bytes()) != sha:
            raise ValueError('Official 1951 PDF checksum differs: ' + name)
    results = []
    with fitz.open(folder / DETAIL_FILE) as detail, fitz.open(folder / SUMMARY_FILE) as summary:
        if ('GENERAL ELECTIONS, INDIA 1951' not in detail[54].get_text().upper()
                or 'PATIALA AND EAST PUNJAB STATES UNION' not in detail[54].get_text().upper()):
            raise ValueError('Detailed report does not establish PEPSU identity')
        for record in revised['records']:
            expected = TARGETS.get(record['code'])
            if expected is None:
                continue
            official, seat, page, electors, voters, winner_votes, runner_votes, margin = expected
            if (record['official_pc_code'] != official
                    or record['state_name'] != 'Patiala And East Punjab States Union'
                    or record['constituency_name'] != seat
                    or record['name'] != record['state_name'] + ' / ' + seat
                    or record['detail_page'] != 162 or record.get('summary_page') is not None
                    or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
                    or record['error'] != OLD_WARNING
                    or tuple(record[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes'))
                    != (electors, voters, voters)
                    or record.get('summary_result') is not None
                    or record.get('summary_totals') is not None
                    or record.get('source_warning_code') is not None
                    or record.get('winner') is not None or record.get('margin') is not None):
                raise ValueError('Live 1951 PEPSU row differs: ' + str(record['code']))
            summary_text = summary[page - 1].get_text(sort=True)
            if re.search(r'STATE/UT\s*:\s*Patiala And East Punj\s+CODE\s*:\s*S13',
                         summary_text, re.I) is None:
                raise ValueError('Official PEPSU state code or truncated heading differs')
            official_record = dict(record, state_name='Patiala And East Punj', state_code='S13')
            summary_name, result = verified_summary(
                summary_text, detail[161].get_text(sort=True), official_record,
                minimum_summary_prefix=len(seat), allow_wrapped_detail_name=True)
            if (summary_name.upper() != seat or result['winner_votes'] != winner_votes
                    or result['runner_votes'] != runner_votes or result['margin'] != margin):
                raise ValueError('Official 1951 PEPSU result differs: ' + str(record['code']))
            record['previous_review_note'] = record['error']
            record['original_extraction_warning'] = record['error']
            record['error'] = REVIEW_NOTE
            record['source_warning_code'] = 'official_summary_turnout_only'
            record['summary_page'] = page
            record['summary_totals'] = {'electors': electors, 'votes_polled': voters,
                                        'valid_candidate_votes': voters}
            record['summary_result'] = result
            record['official_summary_constituency_name'] = summary_name
            record['summary_source_file'] = SUMMARY_FILE
            record['summary_source_sha256'] = SUMMARY_SHA
            record['detail_source_file'] = DETAIL_FILE
            record['detail_source_sha256'] = DETAIL_SHA
            results.append({'code': record['code'], 'official_pc_code': official,
                            'summary_page': page, 'electors': electors, 'votes_polled': voters,
                            'result': result})
    if {row['code'] for row in results} != set(TARGETS):
        raise ValueError('Three single-seat PEPSU results were not verified')
    allowed = {'previous_review_note', 'original_extraction_warning', 'error', 'source_warning_code',
               'summary_page', 'summary_totals', 'summary_result',
               'official_summary_constituency_name', 'summary_source_file', 'summary_source_sha256',
               'detail_source_file', 'detail_source_sha256'}
    for before, after in zip(old['records'], revised['records'], strict=True):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (allowed if before['code'] in TARGETS else set()):
            raise ValueError('Unrelated 1951 record changed: ' + str(before['code']))
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    with tempfile.TemporaryDirectory(prefix='pc-1951-pepsu-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{LIVE_SHA}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            file = staged / relative
            file.parent.mkdir(parents=True, exist_ok=True)
            file.write_bytes(body)
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            archive = packages / f'{kind}-{EDITION}.zip'
            package(staged, archive, 'election-archive', bucket, 8, [relative],
                    LIVE_SHA if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(archive)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as bundle:
            for archive in inner:
                bundle.write(archive, archive.name)
            bundle.writestr('SHA256SUMS', ''.join(f'{digest(archive.read_bytes())}  {archive.name}\n'
                                               for archive in inner))
            bundle.writestr('ARCHIVES', EDITION + '\n')
            bundle.writestr('AUDIT.json', json.dumps({
                'scope': 'Three official 1951 single-seat PEPSU PC summaries; multi-seat seat 4 unchanged',
                'source_url': SOURCE_URL, 'detail_file': DETAIL_FILE, 'detail_sha256': DETAIL_SHA,
                'summary_file': SUMMARY_FILE, 'summary_sha256': SUMMARY_SHA,
                'previous_sha256': LIVE_SHA, 'new_sha256': digest(new_body), 'results': results,
            }, indent=2))
            bundle.writestr('IMPORT.sh', guarded_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': LIVE_SHA,
            'new_sha256': digest(new_body), 'results': len(results)}


if __name__ == '__main__':
    print(json.dumps(build()))
