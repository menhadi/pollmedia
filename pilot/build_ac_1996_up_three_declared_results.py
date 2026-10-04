"""Show three 1996 UP AC declarations while retaining differing report totals."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_ac_2005_official_declared_results import printed_result
from build_pc_ac_zero_turnout_bundle import import_script, normalized
from extract_assembly_summary_totals import parse_summary_page
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '1cc8415ab4d57b66831417e8'
NAME = 'pollmedia-ac-1996-up-three-official-results-20261004'
LIVE_SHA = 'c2931568ddbde41cf7015706b2b5decdf4fb464942fe7deddbc44758e1eb242e'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3259-uttar-pradesh-1996/'
SOURCE_FILE = EDITION + '-7503.pdf'
SOURCE_SHA = 'a08f0f434a0042acb8530d9d0788a0e481c220729fd0fd2f8ec6e8adba0f607d'
DISCREPANCY_NOTE = ('Official 1996 constituency summary turnout, winner and margin are shown. '
                    'The detailed candidate report prints different voter and valid-vote totals; '
                    'both official sets are preserved for review.')
COUNT_NOTE = ('Official 1996 constituency summary confirms turnout, winner and margin. '
              'The reported candidate count differs from the detailed rows, which remain under review.')
TARGETS = {
    4: {'name': 'LANSDOWNE', 'detail_page': 458, 'summary_page': 37,
        'detail': (191989, 101274, 101339), 'summary': (191989, 102735, 99878),
        'winner': ('BHARAT SINGH RAWAT', 'BJP', 56576),
        'runner': ('SURENDRA SINGH NEGI', 'IND', 23366), 'margin': 33210},
    6: {'name': 'KARANPRAYAG', 'detail_page': 459, 'summary_page': 39,
        'detail': (171531, 86107, 86167), 'summary': (171531, 87731, 84543),
        'winner': ('RAMESH POKHARIYAL ZZNISHANKZZ', 'BJP', 37941),
        'runner': ('SHIVA NAND NAUTIYAL', 'INC', 26939), 'margin': 11002},
    399: {'name': 'SIWALKHAS (SC)', 'detail_page': 571, 'summary_page': 431,
          'detail': (238561, 127539, 126122), 'summary': (238561, 127539, 126122),
          'winner': ('VANARSI DAS CHANDNA', 'BKKGP', 43430),
          'runner': ('MURARI LAL KEN', 'BSP', 41277), 'margin': 2153},
}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def totals(values: tuple[int, int, int]) -> dict:
    return dict(zip(('electors', 'votes_polled', 'valid_candidate_votes'), values, strict=True))


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


def detailed_section(pdf: fitz.Document, code: int, page: int) -> str:
    text = '\n'.join(pdf[index].get_text(sort=True) for index in (page - 1, page))
    match = re.search(r'Constituency\s*:\s*' + str(code) + r'\s*\.\s*(.*?)'
                      r'(?=\nConstituency\s*:|\Z)', text, re.I | re.S)
    if match is None:
        raise ValueError('Detailed 1996 constituency is missing: ' + str(code))
    return match[0]


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (root / 'tmp/ac-1996-up-live-20261004.json').read_bytes()
    if digest(old_body) != LIVE_SHA:
        raise ValueError('Verified 1996 live predecessor differs')
    old = json.loads(old_body)
    revised = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source = [item for item in manifest['files'] if item['file'] == SOURCE_FILE]
    path = folder / SOURCE_FILE
    if (old['kind'] != 'ac' or old['year'] != 1996 or len(old['records']) != 424
            or old['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or old['source_file'] != SOURCE_FILE or old['source_sha256'] != SOURCE_SHA
            or len(source) != 1 or source[0]['sha256'] != SOURCE_SHA
            or path.is_symlink() or digest(path.read_bytes()) != SOURCE_SHA):
        raise ValueError('Official 1996 source identity or PDF checksum differs')
    verified = []
    with fitz.open(path) as pdf:
        if 'UTTAR PRADESH' not in pdf[0].get_text().upper():
            raise ValueError('Official report cover does not establish jurisdiction')
        for record in revised['records']:
            config = TARGETS.get(record['code'])
            if config is None:
                continue
            code = record['code']
            previous = record['error']
            expected_error = (f"Summary and detailed totals differ: votes_polled: detail {config['detail'][1]}, "
                              f"summary {config['summary'][1]}; valid_candidate_votes: detail "
                              f"{config['detail'][2]}, summary {config['summary'][2]}") if code in (4, 6) else (
                              'Official detailed and summary turnout totals agree. The summary candidate count differs '
                              'from the detailed rows; candidate comparisons remain under review.')
            if (record['name'] != config['name'] or record.get('state_name') is not None
                    or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
                    or previous != expected_error or record['detail_page'] != config['detail_page']
                    or record['summary_page'] != config['summary_page']
                    or (record['electors'], record['votes_polled'], record['valid_candidate_votes'])
                    != config['detail'] or record['summary_totals'] != totals(config['summary'])
                    or record.get('summary_result') is not None
                    or record.get('winner') is not None or record.get('margin') is not None):
                raise ValueError('Live 1996 review state differs: ' + str(code))
            if code in (4, 6) and (record.get('source_warning_code') is not None
                                   or record.get('summary_source_file') is not None):
                raise ValueError('1996 discrepancy source state differs: ' + str(code))
            if code == 399 and (record['source_warning_code'] != 'official_summary_turnout_only'
                                or record['summary_source_file'] != SOURCE_FILE
                                or record['summary_source_sha256'] != SOURCE_SHA
                                or record['original_extraction_warning'] != 'Candidate count differs from summary'):
                raise ValueError('1996 candidate-count source state differs')
            text = pdf[config['summary_page'] - 1].get_text(sort=True)
            summary = parse_summary_page(text, config['summary_page'])
            if (summary is None or summary['code'] != code
                    or normalized(summary['name']) != normalized(config['name'])
                    or tuple(summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes'))
                    != config['summary'] or 'Legislative Assembly of UTTAR PRADESH' not in text):
                raise ValueError('Official 1996 summary totals differ: ' + str(code))
            result = printed_result(text, config['summary'][2], code)
            if ((result['winner'], result['winner_party'], result['winner_votes']) != config['winner']
                    or (result['runner'], result['runner_party'], result['runner_votes']) != config['runner']
                    or result['margin'] != config['margin']):
                raise ValueError('Official 1996 declared result differs: ' + str(code))
            detail = detailed_section(pdf, code, config['detail_page'])
            printed_totals = re.search(r'ELECTORS\s*:\s*(\d+)\s+VOTERS\s*:\s*(\d+)'
                                       r'.*?VALID VOTES\s*:\s*(\d+)', detail, re.I | re.S)
            if printed_totals is None or tuple(map(int, printed_totals.groups())) != config['detail']:
                raise ValueError('Official 1996 detailed totals differ: ' + str(code))
            for name, party, votes in (config['winner'], config['runner']):
                if re.search(r'^\s*\d+\s*\.\s*' + re.escape(name) + r'\s+[MF]\s+'
                             + re.escape(party) + r'\s+' + str(votes) + r'\b',
                             detail, re.I | re.M) is None:
                    raise ValueError('Official 1996 candidate row differs: ' + str(code))
            ranked = sorted(record['candidates'], key=lambda row: row['votes'], reverse=True)
            if (len(ranked) < 2 or [(row['candidate_name'], row['party_at_election'], row['votes'])
                                   for row in ranked[:2]] != [config['winner'], config['runner']]
                    or sum(row['votes'] for row in record['candidates']) != config['detail'][2]):
                raise ValueError('Retained 1996 candidate rows differ: ' + str(code))
            record['previous_review_note'] = previous
            record['error'] = DISCREPANCY_NOTE if code in (4, 6) else COUNT_NOTE
            record['summary_result'] = result
            if code in (4, 6):
                record['original_extraction_warning'] = previous
                record['source_warning_code'] = 'official_summary_turnout_only'
                record['votes_polled'] = config['summary'][1]
                record['detailed_report_totals'] = totals(config['detail'])
                record['summary_source_file'] = SOURCE_FILE
                record['summary_source_sha256'] = SOURCE_SHA
            verified.append({'code': code, 'name': config['name'], 'summary_page': config['summary_page'],
                             'detail_page': config['detail_page'], 'detailed_totals': totals(config['detail']),
                             'summary_totals': totals(config['summary']), 'result': result})
    if {row['code'] for row in verified} != set(TARGETS):
        raise ValueError('Three 1996 UP declarations were not verified')
    for before, after in zip(old['records'], revised['records'], strict=True):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        allowed = ({'previous_review_note', 'error', 'summary_result', 'original_extraction_warning',
                    'source_warning_code', 'votes_polled', 'detailed_report_totals',
                    'summary_source_file', 'summary_source_sha256'} if before['code'] in (4, 6)
                   else {'previous_review_note', 'error', 'summary_result'} if before['code'] == 399
                   else set())
        if before['code'] != after['code'] or changed != allowed:
            raise ValueError('Unrelated 1996 extraction evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    with tempfile.TemporaryDirectory(prefix='ac-1996-up-three-', dir=root / 'exports') as directory:
        temp = Path(directory)
        staged = temp / 'archive'
        packages = temp / 'packages'
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
                'scope': 'Three official 1996 Uttar Pradesh AC declarations; detailed totals preserved',
                'edition': EDITION, 'source_url': SOURCE_URL,
                'source_file': SOURCE_FILE, 'source_sha256': SOURCE_SHA,
                'previous_sha256': LIVE_SHA, 'new_sha256': digest(new_body), 'results': verified,
            }, indent=2))
            bundle.writestr('IMPORT.sh', guarded_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': LIVE_SHA,
            'new_sha256': digest(new_body), 'results': len(verified)}


if __name__ == '__main__':
    print(json.dumps(build()))
