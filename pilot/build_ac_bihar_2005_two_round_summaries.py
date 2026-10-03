"""Preserve both Bihar 2005 AC rounds and publish their official summary results."""

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


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'faf93ea0918e67d6bc68067a'
NAME = 'pollmedia-ac-bihar-2005-two-round-summaries-20261003-v2'
PRIOR_BUNDLE = 'pollmedia-bihar-2005-turnout-correction-20261001.zip'
PRIOR_BUNDLE_SHA256 = '6602e62503b129e61390ebad15f37a2082005e26027dc34587d4f43499263f3c'
PRIOR_SHA256 = '2cd7c2c8cbe532156881766af4b68342522da583035987ff0619695d5e0d06a8'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3901-bihar-2005/'
SOURCES = {
    '2005-feb': ('faf93ea0918e67d6bc68067a-9224.pdf', '61b09fc09a7d06373b63174ea5cda8f276cec6f2419b1b91415d7c3d8550ca84'),
    '2005-oct': ('faf93ea0918e67d6bc68067a-9236.pdf', 'ff4f6192840cd830eabbac40ee7f06650e348f12e22b20ea4ee66575c44d963e'),
}
SUMMARY_HEADING = re.compile(r'Field7:CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', re.I)
WINNER = re.compile(r'^\s*Winner\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', re.M | re.I)
RUNNER = re.compile(r'^\s*Runner\s*up\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', re.M | re.I)
MARGIN = re.compile(r'^\s*MARGIN\s*:\s*(\d+)\b', re.M | re.I)
TOTALS = {
    'electors': ('II. ELECTORS', 'III. VOTERS', re.compile(r'^\s*3\. TOTAL\s+.*?\b(\d+)\s*$', re.M | re.I)),
    'votes_polled': ('III. VOTERS', 'IV. VOTES', re.compile(r'^\s*4\. TOTAL\s+.*?\b(\d+)\s*$', re.M | re.I)),
    'valid_candidate_votes': ('IV. VOTES', 'V. POLLING', re.compile(r'^\s*3\. TOTAL VALID VOTES POLLED\s+(\d+)\s*$', re.M | re.I)),
}
NOTE = 'Official summary confirms turnout and the declared result; detailed candidate rows remain under review.'
DIFFERENCE_NOTE = 'Official summary confirms turnout and the declared result; some detailed candidate rows differ or are incomplete. Check the original report.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(value: str) -> str:
    return re.sub(r'[^a-z0-9]', '', value.casefold())


def summary_pages(pdf_path: Path) -> dict[int, dict]:
    found = {}
    with fitz.open(pdf_path) as pdf:
        for page_index in range(len(pdf)):
            text = pdf[page_index].get_text(sort=True)
            if 'CONSTITUENCY DATA - SUMMARY' not in text:
                continue
            heading = SUMMARY_HEADING.search(text)
            winner = WINNER.search(text)
            runner = RUNNER.search(text)
            margin = MARGIN.search(text)
            if not all((heading, winner, runner, margin)):
                raise ValueError(f'Bihar summary result is incomplete on page {page_index + 1}')
            code = int(heading[1])
            if code in found or code not in range(1, 244):
                raise ValueError(f'Bihar summary constituency code differs: {code}')
            totals = {}
            for field, (start, end, pattern) in TOTALS.items():
                start_index, end_index = text.find(start), text.find(end)
                if start_index < 0 or end_index <= start_index:
                    raise ValueError(f'Bihar summary sections differ: {code} {field}')
                match = pattern.search(text[start_index:end_index])
                if match is None:
                    raise ValueError(f'Bihar summary total is missing: {code} {field}')
                totals[field] = int(match[1])
            result = {'winner': winner[2].strip(), 'winner_party': winner[1],
                      'winner_votes': int(winner[3]), 'runner': runner[2].strip(),
                      'runner_party': runner[1], 'runner_votes': int(runner[3]),
                      'margin': int(margin[1])}
            if (not 0 < totals['valid_candidate_votes'] <= totals['votes_polled'] <= totals['electors']
                    or not 0 < result['runner_votes'] < result['winner_votes'] <= totals['valid_candidate_votes']
                    or result['winner_votes'] - result['runner_votes'] != result['margin']):
                raise ValueError(f'Bihar official summary arithmetic differs: {code}')
            found[code] = {'name': heading[2].strip(), 'page': page_index + 1,
                           'totals': totals, 'result': result}
    if set(found) != set(range(1, 244)):
        raise ValueError('Bihar official summary does not contain all 243 constituencies')
    return found


def detailed_top_matches(record: dict, result: dict) -> bool:
    ranked = sorted((candidate for candidate in record['candidates']
                     if not candidate.get('is_nota') and isinstance(candidate.get('votes'), int)),
                    key=lambda candidate: -candidate['votes'])
    if len(ranked) < 2:
        return False
    return all(norm(result[key]) == norm(ranked[index]['candidate_name'])
               and result[key + '_party'] == ranked[index]['party_at_election']
               and result[key + '_votes'] == ranked[index]['votes']
               for index, key in enumerate(('winner', 'runner')))


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    prior_bundle = root / 'exports' / PRIOR_BUNDLE
    if digest(prior_bundle.read_bytes()) != PRIOR_BUNDLE_SHA256:
        raise ValueError('Prior Bihar turnout bundle checksum differs')
    with zipfile.ZipFile(prior_bundle) as outer:
        previous_audit = json.loads(outer.read('AUDIT.json'))
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            before_body = inner.read(f'election-archive/{EDITION}/extraction.json')
    before = json.loads(before_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if (digest(before_body) != PRIOR_SHA256 or previous_audit['new_sha256'] != PRIOR_SHA256
            or before['kind'] != 'ac' or before['year'] != 2005
            or len(before['records']) != 486 or before['source_url'] != SOURCE_URL
            or manifest['url'] != SOURCE_URL
            or (before['source_file'], before['source_sha256']) != SOURCES['2005-feb']
            or len(before.get('additional_sources', [])) != 1
            or (before['additional_sources'][0]['file'], before['additional_sources'][0]['sha256']) != SOURCES['2005-oct']):
        raise ValueError('Original Bihar 2005 archive identity differs')
    pages = {}
    for election_round, (filename, expected_sha) in SOURCES.items():
        source = folder / filename
        matched = [entry for entry in manifest['files'] if entry['file'] == filename]
        if len(matched) != 1 or matched[0]['sha256'] != expected_sha or digest(source.read_bytes()) != expected_sha:
            raise ValueError(f'Official Bihar source checksum differs: {election_round}')
        pages[election_round] = summary_pages(source)
    after = json.loads(before_body)
    discrepancy_codes = {'2005-feb': [], '2005-oct': []}
    seen = {'2005-feb': set(), '2005-oct': set()}
    for record in after['records']:
        election_round = record.get('election_round')
        if election_round not in SOURCES:
            raise ValueError('Unknown Bihar election round')
        code = record.get('official_ac_code')
        evidence = pages[election_round][code]
        if (code in seen[election_round] or record['code'] != (100000 if election_round == '2005-feb' else 200000) + code
                or record.get('number_of_seats') != 1 or record.get('status') != 'needs_review'
                or record.get('source_warning_code') != 'round_specific_summary'
                or norm(record['name'].split(' / ')[0]) != norm(evidence['name'])
                or record['valid_candidate_votes'] != evidence['totals']['valid_candidate_votes']
                or record.get('electors') != evidence['totals']['electors']
                or record.get('votes_polled') != evidence['totals']['votes_polled']
                or record.get('summary_totals') != evidence['totals']
                or record.get('summary_page') != evidence['page']
                or record.get('summary_source_file') != SOURCES[election_round][0]
                or record.get('summary_source_sha256') != SOURCES[election_round][1]
                or not record.get('original_extraction_warning')
                or record.get('summary_result') is not None
                or record['detail_totals']['votes'] > evidence['totals']['votes_polled']):
            raise ValueError(f'Bihar source record differs: {election_round} {code}')
        seen[election_round].add(code)
        difference = not detailed_top_matches(record, evidence['result'])
        if difference:
            discrepancy_codes[election_round].append(code)
        record['previous_review_note'] = record['error']
        record['error'] = DIFFERENCE_NOTE if difference else NOTE
        record['summary_result'] = evidence['result']
    if any(codes != set(range(1, 244)) for codes in seen.values()):
        raise ValueError('Bihar round coverage differs')
    changed_fields = {'previous_review_note', 'error', 'summary_result'}
    for previous, revised in zip(before['records'], after['records']):
        changed = {field for field in set(previous) | set(revised)
                   if previous.get(field) != revised.get(field)}
        if changed != changed_fields:
            raise ValueError(f'Unrelated Bihar extraction changed: {previous["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'scope': 'Bihar 2005 February and October AC official summaries',
             'edition': EDITION, 'source_url': SOURCE_URL,
             'sources': {election_round: {'file': file, 'sha256': sha}
                         for election_round, (file, sha) in SOURCES.items()},
             'round_counts': {election_round: len(codes) for election_round, codes in seen.items()},
             'detail_discrepancy_codes': discrepancy_codes,
             'previous_sha256': digest(before_body), 'new_sha256': digest(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-bihar-2005-rounds-', dir=output.parent) as temporary:
        staging = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{PRIOR_SHA256}.json'
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
                    PRIOR_SHA256 if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for path in inner:
                zipped.write(path, path.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            zipped.writestr('ARCHIVES', EDITION + '\n')
            zipped.writestr('AUDIT.json', json.dumps(audit, indent=2))
            zipped.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    with output.with_suffix('.sha256').open('w', encoding='ascii', newline='\n') as checksum:
        checksum.write(f'{digest(output.read_bytes())}  {output.name}\n')
    return {'bundle': str(output), 'sha256': digest(output.read_bytes()), **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
