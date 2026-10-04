"""Show only the source-declared members in two unresolved 1951 AC summaries."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_1951_pepsu_three_summary_results import guarded_import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
NAME = 'pollmedia-ac-1951-two-reviewed-member-declarations-20261004'
SOURCES = {
    '276e7b08f2a72796688eefc3': {
        'state': 'Bihar', 'url': 'https://old.eci.gov.in/files/file/3886-bihar-1951/',
        'pdf': '276e7b08f2a72796688eefc3-9191.pdf',
        'pdf_sha': '61c779396de0a12dbf0c0f7b55a343d209069b0e8f1f8f84a47433ff99c707d0',
        'prior_zip': 'pollmedia-ac-1951-bihar-53-multi-seat-declared-members-20261004.zip',
        'prior_zip_sha': '5268f8601ad7a6624e66585cc9616e38693a6f3dde87b06959fd1b2c01261533',
        'prior_sha': '1b807badbed4a201dd9d0ce7c71e917c51ac7dffb4f2c91906a9d36ad4d79a8d',
        'records': 276, 'code': 276, 'seats': 2, 'page': 292,
        'totals': (118063, 95393, 95393), 'candidate_sum': 189484,
        'reason': 'candidate_rows_conflict',
        'members': [('MUKUNDA RAM TENTY', 'JHP', 18200),
                    ('GHANI RAM SANTHAL', 'JHP', 17062)],
    },
    '3d43a1c5d9759834aee6f0cb': {
        'state': 'Bombay', 'url': 'https://old.eci.gov.in/files/file/4107-bombay-1951/',
        'pdf': '3d43a1c5d9759834aee6f0cb-9726.pdf',
        'pdf_sha': '8dcef73993ecfec1614eaecc395721c9b3cf4d9e275b6f121d0bc091a9e34fae',
        'prior_zip': 'pollmedia-ac-1951-bombay-47-multi-seat-declared-members-20261004.zip',
        'prior_zip_sha': '44df399cb0a3e582d2e324b0d039a64e94235679e6f4236e82062b44dd57c1bd',
        'prior_sha': '365ad33c0a24ba7af81c63ef861055ac682f813f008b4f24e975cb8bbc688407',
        'records': 268, 'code': 117, 'seats': 3, 'page': 133,
        'totals': (162645, 204878, 204878), 'candidate_sum': 204878,
        'reason': 'incomplete_official_list',
        'members': [('KALE, DATTATRAYA TULSHIRAM', 'INC', 29782),
                    ('MURKUTE, PANDURANG MAHADEO', 'INC', 26563)],
    },
}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(name: str) -> str:
    return re.sub(r'[^a-z0-9]', '', name.casefold())


def prior_body(root: Path, edition: str, config: dict) -> bytes:
    release = root / 'exports' / config['prior_zip']
    if digest(release.read_bytes()) != config['prior_zip_sha']:
        raise ValueError('Prior 1951 AC correction ZIP checksum differs: ' + edition)
    inner_name = f'correction-{edition}.zip'
    with zipfile.ZipFile(release) as outer:
        sums = dict((name, checksum) for checksum, name in
                    (line.split() for line in outer.read('SHA256SUMS').decode('ascii').splitlines()))
        inner_bytes = outer.read(inner_name)
        audit = json.loads(outer.read('AUDIT.json'))
        if (digest(inner_bytes) != sums[inner_name]
                or audit['edition'] != edition or audit['new_sha256'] != config['prior_sha']):
            raise ValueError('Prior 1951 AC correction audit differs: ' + edition)
    with zipfile.ZipFile(io.BytesIO(inner_bytes)) as inner:
        body = inner.read(f'election-archive/{edition}/extraction.json')
    if digest(body) != config['prior_sha']:
        raise ValueError('Prior 1951 AC live extraction bytes differ: ' + edition)
    return body


def official_summary(source: Path, config: dict, record: dict) -> tuple[str, dict, list[dict]]:
    with fitz.open(source) as pdf:
        text = pdf[config['page'] - 1].get_text(sort=True)
    seat = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*(.*?)\s+NUMBER OF SEATS\s*:\s*(\d+)',
                     text, re.I | re.S)
    if (f'LEGISLATIVE ASSEMBLY OF {config["state"].upper()}' not in text.upper()
            or seat is None or int(seat[1]) != config['code']
            or int(seat[3]) != config['seats'] or norm(seat[2]) != norm(record['name'])):
        raise ValueError('Official 1951 AC summary identity differs: ' + str(config['code']))
    electors_section = re.search(r'II\. ELECTORS\b(.*?)III\. ELECTORS WHO VOTED', text, re.I | re.S)
    voters_section = re.search(r'III\. ELECTORS WHO VOTED\b(.*?)IV\. VOTES', text, re.I | re.S)
    votes_section = re.search(r'IV\. VOTES\b(.*?)VI\. DATES', text, re.I | re.S)
    if not all((electors_section, voters_section, votes_section)):
        raise ValueError('Official 1951 AC summary totals are missing')
    electors = re.search(r'1\. TOTAL\s+(\d+)', electors_section[1])
    voters = re.search(r'1\. TOTAL\s+(\d+)', voters_section[1])
    polled = re.search(r'1\. POLLED\s+(\d+)', votes_section[1])
    valid = re.search(r'2\. VALID\s+(\d+)', votes_section[1])
    if not all((electors, voters, polled, valid)):
        raise ValueError('Official 1951 AC summary total fields are missing')
    totals = (int(electors[1]), int(voters[1]), int(valid[1]))
    if (totals != config['totals'] or int(polled[1]) != totals[1]
            or totals != (record['electors'], record['votes_polled'], record['valid_candidate_votes'])):
        raise ValueError('Official 1951 AC source totals differ: ' + str(config['code']))
    found = re.findall(r'^\s*Winner\s+(\d+)\s+(\S+)\s+(.+?)\s+(\d+)\s*$',
                       text, re.I | re.M)
    members = [(name.strip(), party, int(votes)) for _, party, name, votes in found]
    if ([int(member[0]) for member in found] != list(range(1, len(found) + 1))
            or members != config['members']):
        raise ValueError('Official 1951 AC named members differ: ' + str(config['code']))
    expected_matches = 2 if config['reason'] == 'candidate_rows_conflict' else 1
    for name, party, votes in members:
        matches = [candidate for candidate in record['candidates']
                   if candidate['candidate_name'] == name
                   and candidate['party_at_election'] == party and candidate['votes'] == votes]
        if len(matches) != expected_matches:
            raise ValueError('Detailed candidate-row discrepancy differs: ' + str(config['code']))
    candidate_sum = sum(candidate['votes'] for candidate in record['candidates'])
    if (candidate_sum != config['candidate_sum']
            or (config['reason'] == 'candidate_rows_conflict') != (candidate_sum != totals[2])
            or (config['reason'] == 'incomplete_official_list') != (len(members) < config['seats'])):
        raise ValueError('1951 AC reviewed-declaration reason differs: ' + str(config['code']))
    return seat[2].strip(), dict(zip(('electors', 'votes_polled', 'valid_candidate_votes'), totals)), [
        {'name': name, 'party': party, 'votes': votes} for name, party, votes in members
    ]


def reviewed_import_script() -> str:
    script = guarded_import_script()
    marker = 'check_disk\nwhile IFS='
    if script.count(marker) != 1:
        raise ValueError('Guarded election import template changed')
    return script.replace(marker,
                          "grep -Fq 'multiSeatReviewedDeclarations' "
                          "/home/pollmedia/app/application/app/Services/HistoricalElectionAnalytics.php "
                          "|| { echo 'Pull tested reviewed-member code first' >&2; exit 1; }\n"
                          + marker)


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    details = []
    with tempfile.TemporaryDirectory(prefix='ac-1951-reviewed-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        archives = []
        for edition, config in SOURCES.items():
            folder = root / 'application/storage/app/private/election-archive' / edition
            source = folder / config['pdf']
            old_body = prior_body(root, edition, config)
            manifest = json.loads((folder / 'manifest.json').read_bytes())
            files = {file['file']: file for file in manifest['files']}
            before = json.loads(old_body)
            after = json.loads(old_body)
            if (source.is_symlink() or digest(source.read_bytes()) != config['pdf_sha']
                    or manifest['url'] != config['url'] or before['source_url'] != config['url']
                    or before['source_file'] != config['pdf']
                    or before['source_sha256'] != config['pdf_sha']
                    or files[config['pdf']]['sha256'] != config['pdf_sha']
                    or before['kind'] != 'ac' or before['year'] != 1951
                    or len(before['records']) != config['records']):
                raise ValueError('Official 1951 AC source or extraction identity differs: ' + edition)
            target = next((record for record in after['records'] if record['code'] == config['code']), None)
            if (target is None or target.get('number_of_seats') != config['seats']
                    or target.get('state_name') != config['state']
                    or target.get('status') != 'needs_review' or not target.get('error')
                    or target.get('summary_page') is not None
                    or target.get('source_warning_code') is not None
                    or target.get('official_multi_seat_review_members') is not None):
                raise ValueError('Unresolved 1951 AC record state differs: ' + edition)
            name, totals, members = official_summary(source, config, target)
            target['previous_review_note'] = target['error']
            target['original_extraction_warning'] = target['error']
            if config['reason'] == 'candidate_rows_conflict':
                target['error'] = ('The official summary names two members, but duplicated detailed candidate '
                                   'rows total 189,484 votes against 95,393 official valid votes. '
                                   'The source names and votes are shown under review; candidate rows are unchanged.')
            else:
                target['error'] = ('The official summary names only two of three elected members; no third '
                                   'member is inferred. It prints 204,878 voters against 162,645 electors; '
                                   'these multi-seat vote totals are not ordinary turnout.')
            target['source_warning_code'] = 'official_multi_seat_reviewed_declaration'
            target['summary_page'] = config['page']
            target['summary_totals'] = totals
            target['summary_source_file'] = config['pdf']
            target['summary_source_sha256'] = config['pdf_sha']
            target['official_summary_constituency_name'] = name
            target['official_multi_seat_review_members'] = members
            target['official_multi_seat_review_reason'] = config['reason']
            allowed = {'previous_review_note', 'original_extraction_warning', 'error', 'source_warning_code',
                       'summary_page', 'summary_totals', 'summary_source_file', 'summary_source_sha256',
                       'official_summary_constituency_name', 'official_multi_seat_review_members',
                       'official_multi_seat_review_reason'}
            for old, new in zip(before['records'], after['records'], strict=True):
                changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
                if old['code'] != new['code'] or changed != (allowed if old['code'] == config['code'] else set()):
                    raise ValueError('Unrelated 1951 AC extraction evidence changed: ' + edition)
            new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
            snapshot = f'election-archive/{edition}/extraction-{config["prior_sha"]}.json'
            revision = f'election-archive/{edition}/extraction.json'
            for kind, relative, body in (('snapshot', snapshot, old_body),
                                         ('correction', revision, new_body)):
                file = staged / relative
                file.parent.mkdir(parents=True, exist_ok=True)
                file.write_bytes(body)
                bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
                archive = packages / f'{kind}-{edition}.zip'
                package(staged, archive, 'election-archive', bucket, 8, [relative],
                        config['prior_sha'] if kind == 'correction' else None,
                        snapshot if kind == 'correction' else None)
                archives.append(archive)
            details.append({'edition': edition, 'source_url': config['url'], 'source_file': config['pdf'],
                            'source_sha256': config['pdf_sha'], 'previous_sha256': config['prior_sha'],
                            'new_sha256': digest(new_body), 'code': config['code'],
                            'number_of_seats': config['seats'], 'summary_page': config['page'],
                            'members_named': members, 'review_reason': config['reason']})
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as bundle:
            for archive in archives:
                bundle.write(archive, archive.name)
            bundle.writestr('SHA256SUMS', ''.join(f'{digest(archive.read_bytes())}  {archive.name}\n'
                                               for archive in archives))
            bundle.writestr('ARCHIVES', ''.join(edition + '\n' for edition in SOURCES))
            bundle.writestr('AUDIT.json', json.dumps({'scope': 'Two source-named 1951 AC member lists under review',
                                                    'editions': details}, indent=2))
            bundle.writestr('IMPORT.sh', reviewed_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'editions': details}


if __name__ == '__main__':
    print(json.dumps(build()))
