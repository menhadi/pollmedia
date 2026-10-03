"""Publish official Arunachal 2004 AC results while retaining absent voter totals."""

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
EDITION = 'dc469be7915c7a5a9e699aac'
NAME = 'pollmedia-ac-arunachal-2004-official-results-20261004'
PRIOR_BUNDLE = 'pollmedia-pc-ac-zero-turnout-corrections-20261001-v3.zip'
PRIOR_BUNDLE_SHA256 = '5449afc95c14440ecb3865d7ffe9717886ce8576f6f2a8ad5f0ce5667b86e8d7'
PREVIOUS_SHA256 = '381571d7df41fcb5407bf16e1d3db503f177eb88ea2c5002398a9173f8dc03b1'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4038-arunachal-pradesh-2004/'
SOURCE_FILE = f'{EDITION}-9579.pdf'
SOURCE_SHA256 = 'a6a2d830c8969fc7364cd70457593b46bc5edf5b854284bae52668ee9d05f76b'
NO_VOTER_TOTAL = {22, 24, 33, 46}
UNCONTESTED = {3, 4, 15}
HEADING = re.compile(r'Field7:CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', re.I)
WINNER = re.compile(r'^\s*Winner\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', re.M | re.I)
RUNNER = re.compile(r'^\s*Runner up\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', re.M | re.I)
MARGIN = re.compile(r'^\s*MARGIN\s*:\s*(\d+)\b', re.M | re.I)


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(value: str) -> str:
    return re.sub(r'[^a-z0-9]', '', value.casefold())


def printed_total(text: str, label: str) -> int | None:
    line = re.search(r'^\s*' + re.escape(label) + r'[^\n]*$', text, re.M | re.I)
    if line is None:
        raise ValueError(f'Official Arunachal total row missing: {label}')
    values = re.findall(r'\b\d+\b', line[0][line[0].upper().find(label.upper()) + len(label):])
    return int(values[-1]) if values else None


def summaries(source: Path) -> dict[int, dict]:
    found = {}
    with fitz.open(source) as pdf:
        for page_index in range(len(pdf)):
            text = pdf[page_index].get_text(sort=True)
            if 'CONSTITUENCY DATA - SUMMARY' not in text:
                continue
            heading = HEADING.search(text)
            if heading is None:
                raise ValueError(f'Official Arunachal heading missing on page {page_index + 1}')
            code, name = int(heading[1]), heading[2].strip()
            if code in found or code not in range(1, 61) or page_index + 1 != code + 12:
                raise ValueError(f'Official Arunachal summary order differs: {code}')
            elect = text[text.index('II. ELECTORS'):text.index('III. VOTERS')]
            voters = text[text.index('III. VOTERS'):text.index('IV. VOTES')]
            votes = text[text.index('IV. VOTES'):text.index('V. POLLING STATIONS')]
            electors = printed_total(elect, '3. TOTAL')
            polled = printed_total(voters, '4. TOTAL')
            valid = printed_total(votes, '3. TOTAL VALID VOTES POLLED')
            winner, runner, margin = WINNER.search(text), RUNNER.search(text), MARGIN.search(text)
            if code in UNCONTESTED:
                if any((winner, runner, margin)) or 'Uncontested' not in text or valid is not None or polled is not None:
                    raise ValueError(f'Official Arunachal uncontested result differs: {code}')
                result = None
            else:
                if not all((winner, runner, margin)) or not 0 < electors or not 0 < valid <= electors:
                    raise ValueError(f'Official Arunachal declared result missing: {code}')
                result = {'winner': winner[2].strip(), 'winner_party': winner[1], 'winner_votes': int(winner[3]),
                          'runner': runner[2].strip(), 'runner_party': runner[1], 'runner_votes': int(runner[3]),
                          'margin': int(margin[1])}
                if (not 0 < result['runner_votes'] < result['winner_votes'] <= valid
                        or result['margin'] != result['winner_votes'] - result['runner_votes']):
                    raise ValueError(f'Official Arunachal result arithmetic differs: {code}')
                if (code in NO_VOTER_TOTAL) != (polled is None):
                    raise ValueError(f'Official Arunachal voter-total availability differs: {code}')
                if polled is not None and not 0 < polled <= electors:
                    raise ValueError(f'Official Arunachal turnout differs: {code}')
            found[code] = {'name': name, 'page': page_index + 1, 'electors': electors,
                           'votes_polled': polled, 'valid_candidate_votes': valid, 'result': result}
    if set(found) != set(range(1, 61)):
        raise ValueError('Official Arunachal constituency summary coverage differs')
    return found


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    prior_bundle = root / 'exports' / PRIOR_BUNDLE
    if sha(prior_bundle.read_bytes()) != PRIOR_BUNDLE_SHA256:
        raise ValueError('Prior Arunachal turnout bundle checksum differs')
    with zipfile.ZipFile(prior_bundle) as outer:
        entry = next(row for row in json.loads(outer.read('AUDIT.json'))['editions'] if row['edition'] == EDITION)
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            before_body = inner.read(f'election-archive/{EDITION}/extraction.json')
    before = json.loads(before_body)
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    source = folder / SOURCE_FILE
    if (sha(before_body) != PREVIOUS_SHA256 or entry['new_sha256'] != PREVIOUS_SHA256
            or before['kind'] != 'ac' or before['year'] != 2004 or len(before['records']) != 60
            or before['source_url'] != SOURCE_URL or before['source_file'] != SOURCE_FILE
            or before['source_sha256'] != SOURCE_SHA256 or manifest['url'] != SOURCE_URL
            or len([row for row in manifest['files'] if row['file'] == SOURCE_FILE and row['sha256'] == SOURCE_SHA256]) != 1
            or sha(source.read_bytes()) != SOURCE_SHA256):
        raise ValueError('Arunachal source edition identity differs')
    pages = summaries(source)
    after = json.loads(before_body)
    changed_codes, seen = [], set()
    for record in after['records']:
        code = record['code']
        if code in seen or code not in pages or record['status'] != 'needs_review' or record['number_of_seats'] != 1:
            raise ValueError(f'Arunachal record identity differs: {code}')
        seen.add(code)
        source_row = pages[code]
        if norm(record['name']) != norm(source_row['name']):
            raise ValueError(f'Arunachal constituency name differs: {code}')
        if code in UNCONTESTED:
            continue
        if (record['valid_candidate_votes'] != source_row['valid_candidate_votes']
                or not record.get('detail_page') or not record.get('error')):
            raise ValueError(f'Arunachal detailed record differs: {code}')
        result = source_row['result']
        ranked = sorted(record['candidates'], key=lambda candidate: -candidate['votes'])
        if len(ranked) < 2 or any(
            norm(result[key]) != norm(ranked[index]['candidate_name'])
            or result[key + '_party'] != ranked[index]['party_at_election']
            or result[key + '_votes'] != ranked[index]['votes']
            for index, key in enumerate(('winner', 'runner'))
        ):
            raise ValueError(f'Arunachal official result differs from candidate rows: {code}')
        record['previous_review_note'] = record['error']
        record['summary_result'] = result
        if code in NO_VOTER_TOTAL:
            if record.get('votes_polled') is not None or record.get('summary_page') is not None:
                raise ValueError(f'Arunachal voter total unexpectedly present: {code}')
            record['electors'] = source_row['electors']
            record['original_extraction_warning'] = record['previous_review_note']
            record['source_warning_code'] = 'official_summary_result_without_turnout'
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = SOURCE_SHA256
            record['summary_page'] = source_row['page']
            record['summary_totals'] = {key: source_row[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
            record['error'] = 'Official summary declares the winner and margin but omits the voter total; turnout remains unavailable.'
        else:
            if (record['electors'] != source_row['electors'] or record['votes_polled'] != source_row['votes_polled']
                    or record['summary_page'] != source_row['page']
                    or record['summary_totals'] != {key: source_row[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
                    or not record.get('original_extraction_warning')):
                raise ValueError(f'Prior Arunachal turnout correction differs: {code}')
            if record.get('source_warning_code') is None:
                if code not in {20, 30, 60} or record.get('summary_source_file') is not None:
                    raise ValueError(f'Arunachal prior summary source status differs: {code}')
                record['source_warning_code'] = 'official_summary_turnout_only'
                record['summary_source_file'] = SOURCE_FILE
                record['summary_source_sha256'] = SOURCE_SHA256
            elif (record['source_warning_code'] != 'official_summary_turnout_only'
                  or record['summary_source_file'] != SOURCE_FILE or record['summary_source_sha256'] != SOURCE_SHA256):
                raise ValueError(f'Arunachal prior summary source differs: {code}')
            record['error'] = ('Official summary confirms the declared result. Printed valid votes exceed printed voters; '
                               'turnout uses the voter total.' if record['valid_candidate_votes'] > record['votes_polled']
                               else 'Official summary confirms turnout and the declared result; detailed candidate text remains under review.')
        changed_codes.append(code)
    if seen != set(range(1, 61)) or set(changed_codes) != set(range(1, 61)) - UNCONTESTED:
        raise ValueError('Arunachal contested and uncontested coverage differs')
    for old, new in zip(before['records'], after['records']):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] in UNCONTESTED:
            expected = set()
        elif old['code'] in NO_VOTER_TOTAL:
            expected = {'previous_review_note', 'summary_result', 'electors', 'original_extraction_warning',
                        'source_warning_code', 'summary_source_file', 'summary_source_sha256', 'summary_page',
                        'summary_totals', 'error'}
        elif old['code'] in {20, 30, 60}:
            expected = {'previous_review_note', 'summary_result', 'source_warning_code',
                        'summary_source_file', 'summary_source_sha256', 'error'}
        else:
            expected = {'previous_review_note', 'summary_result', 'error'}
        if changed != expected or old['candidates'] != new['candidates']:
            raise ValueError(f'Unrelated Arunachal extraction changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'scope': 'Arunachal Pradesh 2004 AC official declared results', 'edition': EDITION,
             'source_url': SOURCE_URL, 'source_file': SOURCE_FILE, 'source_sha256': SOURCE_SHA256,
             'declared_result_count': len(changed_codes), 'missing_voter_total_codes': sorted(NO_VOTER_TOTAL),
             'uncontested_codes': sorted(UNCONTESTED), 'previous_sha256': sha(before_body),
             'new_sha256': sha(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-arunachal-2004-', dir=output.parent) as temporary:
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
