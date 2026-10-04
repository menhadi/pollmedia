"""Source-backed declarations for two 1957 PC seats with two zero-vote rows."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'a1b887ea9c50978fe4cf8e5c'
NAME = 'pollmedia-pc-1957-two-uncontested-results-20261004'
LIVE_SHA = '76d585e4220d9023a0519f7edf3ddad1f84bc9ba40ad1dd0f8008cb447a0cae4'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4112-general-election-1957-vol-i-ii/'
DETAIL_FILE = EDITION + '-9737.pdf'
DETAIL_SHA = '71c6ed66b152cb247d9feedf09743f38eaa9af21e4cf6779eb5e2ee9e206eb02'
SUMMARY_FILE = EDITION + '-9738.pdf'
SUMMARY_SHA = 'c9ab7014c3399762f85a1fc7fc0d001061b675ec83c482a29f8ddc77f85b7726'
PREVIOUS_NOTE = 'Uncontested or inconsistent elector/voter totals; Summary value missing or ambiguous: TOTAL'
NOTE = ('Official detailed and constituency summary reports declare an unopposed winner. '
        'No votes, turnout or margin are reported; the second zero-vote candidate row remains under review.')
TARGETS = {
    18: {'state': 'Andhra Pradesh', 'seat': 'RAJAMPET', 'official_code': 18, 'electors': 417694,
         'detail_page': 73, 'summary_page': 21, 'winner': 'T.N. VISWANATH REDDY',
         'other': 'S. HUSSAIN SHAH'},
    215: {'state': 'Madras', 'seat': 'TIRUCHENDUR', 'official_code': 24, 'electors': 448411,
          'detail_page': 100, 'summary_page': 218, 'winner': 'T. GANAPATHY',
          'other': 'N. DURIPANDI'},
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
                            "grep -Fq 'official_uncontested_with_zero_vote_rows' "
                            "/home/pollmedia/app/application/app/Services/HistoricalElectionAnalytics.php "
                            "|| { echo 'Pull the tested election code first' >&2; exit 1; }\n"
                            'check_disk\nwhile IFS=')
    return script


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (root / 'tmp/pc-1957-live-20261004.json').read_bytes()
    if digest(old_body) != LIVE_SHA:
        raise ValueError('Live 1957 predecessor SHA differs')
    old = json.loads(old_body)
    revised = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    files = {source['file']: source for source in manifest['files']}
    if (old['kind'] != 'pc' or old['year'] != 1957 or old['source_url'] != SOURCE_URL
            or manifest['url'] != SOURCE_URL or old['source_file'] != DETAIL_FILE
            or old['source_sha256'] != DETAIL_SHA):
        raise ValueError('1957 PC edition identity differs')
    for name, sha in ((DETAIL_FILE, DETAIL_SHA), (SUMMARY_FILE, SUMMARY_SHA)):
        path = folder / name
        if path.is_symlink() or files[name]['sha256'] != sha or digest(path.read_bytes()) != sha:
            raise ValueError('Official 1957 PDF SHA differs: ' + name)
    evidence = json.loads((root / 'application/database/fixtures/official-uncontested-results.json').read_text(encoding='utf-8'))
    results = []
    with fitz.open(folder / DETAIL_FILE) as detail, fitz.open(folder / SUMMARY_FILE) as summary:
        for record in revised['records']:
            source = TARGETS.get(record['code'])
            if source is None:
                continue
            code = record['code']
            fixture = evidence[EDITION + ':' + str(code)]
            if (record['state_name'] != source['state'] or record['constituency_name'] != source['seat']
                    or record['official_pc_code'] != source['official_code']
                    or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
                    or record['error'] != PREVIOUS_NOTE
                    or (record['electors'], record['votes_polled'], record['valid_candidate_votes'])
                    != (source['electors'], 0, 0)
                    or (record['detail_page'], record['summary_page'])
                    != (source['detail_page'], source['summary_page'])
                    or record.get('source_warning_code') is not None
                    or record.get('summary_result') is not None
                    or record.get('winner') is not None or record.get('margin') is not None
                    or [(row['candidate_name'], row['party_at_election'], row['votes'])
                        for row in record['candidates']]
                    != [(source['other'], 'IND', 0), (source['winner'], 'INC', 0)]):
                raise ValueError('Live 1957 record differs for ' + str(code))
            if (fixture['name'] != source['seat'] or fixture['candidate'] != source['winner']
                    or fixture['party'] != 'INC' or fixture['other_candidate'] != source['other']
                    or fixture['other_party'] != 'IND' or fixture['electors'] != source['electors']
                    or fixture['source_url'] != SOURCE_URL or fixture['source_file'] != SUMMARY_FILE
                    or fixture['source_sha256'] != SUMMARY_SHA
                    or fixture['pdf_page'] != source['summary_page']):
                raise ValueError('1957 source fixture differs for ' + str(code))
            detail_text = detail[source['detail_page'] - 1].get_text(sort=True)
            summary_text = summary[source['summary_page'] - 1].get_text(sort=True)
            if (re.search(r'Constituency\s+' + str(source['official_code']) + r'\s+'
                          + re.escape(source['seat']) + r'\s+NUMBER OF SEATS\s+1', detail_text, re.I) is None
                    or re.search(re.escape(source['winner']) + r'\s+INC\s+RETURNED UNCONTESTED',
                                 detail_text, re.I) is None
                    or re.search(r'ELECTORS\s*:\s*' + str(source['electors'])
                                 + r'\s+VOTERS\s*:\s*0\b', detail_text, re.I) is None
                    or re.search(r'STATE/UT\s*:\s*' + re.escape(source['state']), summary_text, re.I) is None
                    or re.search(r'CONSTITUENCY\s*:\s*' + str(source['official_code'])
                                 + r'\s*-\s*' + re.escape(source['seat']), summary_text, re.I) is None
                    or re.search(r'Winner\s+INC\s+' + re.escape(source['winner'])
                                 + r'\s+Returned\s+Uncontested', summary_text, re.I) is None):
                raise ValueError('1957 official declaration differs for ' + str(code))
            record['original_extraction_warning'] = record['error']
            record['previous_review_note'] = record['error']
            record['error'] = NOTE
            record['source_warning_code'] = 'official_uncontested_with_zero_vote_rows'
            record['official_source_url'] = SOURCE_URL
            record['summary_source_file'] = SUMMARY_FILE
            record['summary_source_sha256'] = SUMMARY_SHA
            results.append({'code': code, 'name': source['seat'], 'winner': source['winner'],
                            'party': 'INC', 'summary_page': source['summary_page'],
                            'detail_page': source['detail_page'], 'turnout': None, 'margin': None})
    if {row['code'] for row in results} != set(TARGETS):
        raise ValueError('Both 1957 unopposed seats were not verified')
    allowed = {'original_extraction_warning', 'previous_review_note', 'error',
               'source_warning_code', 'official_source_url', 'summary_source_file',
               'summary_source_sha256'}
    for before, after in zip(old['records'], revised['records'], strict=True):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (allowed if before['code'] in TARGETS else set()):
            raise ValueError('Unrelated 1957 extraction changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    with tempfile.TemporaryDirectory(prefix='pc-1957-uncontested-', dir=root / 'exports') as directory:
        temp = Path(directory)
        staged = temp / 'archive'
        packages = temp / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{LIVE_SHA}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
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
                'scope': 'Two official 1957 PC unopposed declarations; turnout and candidate rows unchanged',
                'edition': EDITION, 'source_url': SOURCE_URL,
                'detail_file': DETAIL_FILE, 'detail_sha256': DETAIL_SHA,
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
