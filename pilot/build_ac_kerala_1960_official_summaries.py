"""Add the Kerala 1960 official AC summaries without treating two-seat contests as single-seat."""

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
EDITION = 'c7c15b329cbd2123a34e5b3e'
SOURCE_FILE = f'{EDITION}-8815.pdf'
SOURCE_SHA256 = '84f2d9538f42f21f2c8d9ad06396dc867f81798f52e264c48bb6bb5792975975'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3748-kerala-1960/'
PREVIOUS_SHA256 = '483c4fda8b3401c88e0d23eb84e9333e633f6d9faa30e3228b80f4751ce609f2'
NAME = 'pollmedia-ac-kerala-1960-official-summaries-20261004'
HEADING = re.compile(r'Field7:CONSTITUENCY\s*:\s*(\d+)\s*-\s*(.*?)\s+NUMBER OF SEATS\s*:\s*(\d+)', re.I)
WINNER = re.compile(r'^\s*Winner\s+(\S+)\s+(.+?)\s+(\d+)\s*$', re.M | re.I)
RUNNER = re.compile(r'^\s*Runner up\s+(\S+)\s+(.+?)\s+(\d+)\s*$', re.M | re.I)
MULTI_WINNER = re.compile(r'^\s*Winner\s+([12])\s+(\S+)\s+(.+?)\s+(\d+)\s*$', re.M | re.I)
MARGIN = re.compile(r'^\s*MARGIN\s*:\s*(\d+)\b', re.M | re.I)


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(value: str) -> str:
    return re.sub(r'[^a-z0-9]', '', value.casefold())


def total(section: str, label: str) -> int:
    match = re.search(r'^\s*' + re.escape(label) + r'\s+.*?\b(\d+)\s*$', section, re.M | re.I)
    if match is None:
        raise ValueError(f'Missing Kerala source total: {label}')
    return int(match[1])


def summaries(source: Path) -> dict[int, dict]:
    found = {}
    with fitz.open(source) as pdf:
        for page_index in range(len(pdf)):
            text = pdf[page_index].get_text(sort=True)
            if 'CONSTITUENCY DATA - SUMMARY' not in text:
                continue
            heading = HEADING.search(text)
            if heading is None:
                raise ValueError(f'Missing Kerala constituency on page {page_index + 1}')
            code, name, seats = int(heading[1]), heading[2].strip(), int(heading[3])
            if code in found or code not in range(1, 115) or seats not in (1, 2):
                raise ValueError(f'Unexpected Kerala constituency or seat count: {code}')
            electors = total(text[text.index('II. ELECTORS'):text.index('III. ELECTORS WHO VOTED')], '1. TOTAL')
            voted = total(text[text.index('III. ELECTORS WHO VOTED'):text.index('IV. VOTES')], '1. TOTAL')
            votes = text[text.index('IV. VOTES'):text.index('VI. DATES')]
            polled_match = re.search(r'^\s*1\. POLLED\s+(\d+)\b', votes, re.M | re.I)
            if polled_match is None:
                raise ValueError(f'Missing Kerala votes polled: {code}')
            polled, valid = int(polled_match[1]), total(votes, '2. VALID')
            if voted != polled or not 0 < electors or not 0 < valid <= polled:
                raise ValueError(f'Kerala source totals differ: {code}')
            result = None
            winners = None
            if seats == 1:
                winner, runner, margin = WINNER.search(text), RUNNER.search(text), MARGIN.search(text)
                if not all((winner, runner, margin)) or polled > electors:
                    raise ValueError(f'Kerala one-seat result differs: {code}')
                result = {'winner': winner[2].strip(), 'winner_party': winner[1], 'winner_votes': int(winner[3]),
                          'runner': runner[2].strip(), 'runner_party': runner[1], 'runner_votes': int(runner[3]),
                          'margin': int(margin[1])}
                if not 0 < result['runner_votes'] < result['winner_votes'] <= valid or result['margin'] != result['winner_votes'] - result['runner_votes']:
                    raise ValueError(f'Kerala source margin differs: {code}')
            else:
                source_winners = MULTI_WINNER.findall(text)
                if len(source_winners) != 2 or [int(row[0]) for row in source_winners] != [1, 2] or MARGIN.search(text):
                    raise ValueError(f'Kerala two-seat result differs: {code}')
                winners = [{'name': row[2].strip(), 'party': row[1], 'votes': int(row[3])} for row in source_winners]
            found[code] = {'name': name, 'seats': seats, 'page': page_index + 1,
                           'electors': electors, 'votes_polled': polled, 'valid_candidate_votes': valid,
                           'result': result, 'winners': winners}
    if set(found) != set(range(1, 115)) or sum(row['seats'] == 2 for row in found.values()) != 12:
        raise ValueError('Kerala official summary coverage differs')
    return found


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    before_body = (folder / 'extraction.json').read_bytes()
    before = json.loads(before_body)
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    source = folder / SOURCE_FILE
    if (sha(before_body) != PREVIOUS_SHA256 or before['kind'] != 'ac' or before['year'] != 1960
            or before['source_url'] != SOURCE_URL or len(before['records']) != 114
            or manifest['url'] != SOURCE_URL
            or len([entry for entry in manifest['files'] if entry['file'] == SOURCE_FILE and entry['sha256'] == SOURCE_SHA256]) != 1
            or sha(source.read_bytes()) != SOURCE_SHA256):
        raise ValueError('Kerala source edition identity differs')
    pages = summaries(source)
    after = json.loads(before_body)
    seen, multi_codes = set(), []
    for record in after['records']:
        code = record['code']
        if code in seen or code not in pages or record['status'] != 'needs_review' or record['number_of_seats'] != 1:
            raise ValueError(f'Kerala original record identity differs: {code}')
        seen.add(code)
        source_row = pages[code]
        if (norm(record['name']) != norm(source_row['name'])
                or any(record[key] != source_row[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes'))
                or not record.get('error') or not record.get('detail_page')):
            raise ValueError(f'Kerala original record differs from official summary: {code}')
        record['original_extraction_warning'] = record['error']
        record['summary_source_file'] = SOURCE_FILE
        record['summary_source_sha256'] = SOURCE_SHA256
        record['summary_page'] = source_row['page']
        record['summary_totals'] = {key: source_row[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
        if source_row['seats'] == 1:
            ranked = sorted(record['candidates'], key=lambda candidate: -candidate['votes'])
            result = source_row['result']
            if len(ranked) < 2 or any(
                norm(result[key]) != norm(ranked[index]['candidate_name'])
                or result[key + '_party'] != ranked[index]['party_at_election']
                or result[key + '_votes'] != ranked[index]['votes']
                for index, key in enumerate(('winner', 'runner'))
            ):
                raise ValueError(f'Kerala source candidate rows differ: {code}')
            record['source_warning_code'] = 'summary_only_turnout'
            record['summary_result'] = result
            record['error'] = 'Official summary confirms turnout and the declared result; detailed candidate text remains under review.'
        else:
            multi_codes.append(code)
            if (sum(candidate['votes'] for candidate in record['candidates']) != source_row['valid_candidate_votes']
                    or any(sum(1 for candidate in record['candidates']
                               if norm(candidate['candidate_name']) == norm(winner['name'])
                               and candidate['party_at_election'] == winner['party']
                               and candidate['votes'] == winner['votes']) != 1
                           for winner in source_row['winners'])):
                raise ValueError(f'Kerala two-seat candidate rows differ: {code}')
            record['number_of_seats'] = 2
            record['source_warning_code'] = 'official_multi_seat_summary'
            record['official_multi_seat_winners'] = source_row['winners']
            record['error'] = 'Two-seat constituency: the official report declares two winners. Candidate votes are not one-seat turnout; see the original report.'
    if seen != set(range(1, 115)):
        raise ValueError('Kerala extraction record coverage differs')
    for old, new in zip(before['records'], after['records']):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        expected = {'original_extraction_warning', 'summary_source_file', 'summary_source_sha256', 'summary_page',
                    'summary_totals', 'source_warning_code', 'error'}
        expected |= ({'summary_result'} if new['number_of_seats'] == 1 else {'number_of_seats', 'official_multi_seat_winners'})
        if changed != expected or old['candidates'] != new['candidates']:
            raise ValueError(f'Unrelated Kerala extraction changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'scope': 'Kerala 1960 AC official summaries', 'edition': EDITION, 'source_url': SOURCE_URL,
             'source_file': SOURCE_FILE, 'source_sha256': SOURCE_SHA256, 'single_seat_results': 102,
             'two_seat_constituencies': multi_codes, 'previous_sha256': sha(before_body), 'new_sha256': sha(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-kerala-1960-', dir=output.parent) as temporary:
        staging = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{PREVIOUS_SHA256}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        for relative, body in ((snapshot, before), (revision, after)):
            path = staging / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
        inner = []
        for kind, relative in (('snapshot', snapshot), ('correction', revision)):
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staging, path, 'election-archive', bucket, 8, [relative],
                    PREVIOUS_SHA256 if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for path in inner:
                zipped.write(path, path.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{sha(path.read_bytes())}  {path.name}\n' for path in inner))
            zipped.writestr('ARCHIVES', EDITION + '\n')
            zipped.writestr('AUDIT.json', json.dumps(audit, indent=2))
            zipped.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    with output.with_suffix('.sha256').open('w', encoding='ascii', newline='\n') as checksum:
        checksum.write(f'{sha(output.read_bytes())}  {output.name}\n')
    return {'bundle': str(output), 'sha256': sha(output.read_bytes()), **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
