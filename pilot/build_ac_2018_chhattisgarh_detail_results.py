"""Expose two fully reconciled Chhattisgarh 2018 AC detailed results."""

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
EDITION = 'cccb0490a067808ecaa71a36'
NAME = 'pollmedia-ac-chhattisgarh-2018-detail-results-20261003'
PRIOR_BUNDLE = 'pollmedia-ac-residual-turnout-corrections-20261001-v4.zip'
PRIOR_BUNDLE_SHA256 = 'ad1c4fcc0c246805bc44974882776d2503677faad549a0b5857f402bf4dd69c0'
PRIOR_SHA256 = '70ced9164af2a7b19df4d56c464b8b9d284af251d9f3c63aaa2d9ae2f08dc549'
SOURCE_SHA256 = 'e27747ee54de98b27b8d3427c61ffbd06057e1d09cf2db9f0cc035f9f85e65b5'
SOURCE_URL = 'https://old.eci.gov.in/files/file/9643-statistical-data-of-general-election-to-chhatisgarh-assembly-2018/'
TARGETS = {64: ('Durg City', 44, 64981, 43900), 80: ('Bhanupratappur (ST)', 55, 72520, 45827)}
NOTE = 'Official source prints the constituency turnout total; previous candidate/source warnings remain available for review.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def prior_body(root: Path) -> bytes:
    path = root / 'exports' / PRIOR_BUNDLE
    if digest(path.read_bytes()) != PRIOR_BUNDLE_SHA256:
        raise ValueError('Prior correction bundle checksum differs')
    with zipfile.ZipFile(path) as outer:
        audit = json.loads(outer.read('AUDIT.json'))
        previous = next(e for e in audit['editions'] if e['edition'] == EDITION)
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if previous['new_sha256'] != PRIOR_SHA256 or digest(body) != PRIOR_SHA256:
        raise ValueError('Prior Chhattisgarh extraction differs')
    return body


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    before_body = prior_body(root)
    before = json.loads(before_body)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source = folder / before['source_file']
    if (before['kind'] != 'ac' or before['year'] != 2018 or len(before['records']) != 90
            or before['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or before['source_sha256'] != SOURCE_SHA256 or digest(source.read_bytes()) != SOURCE_SHA256):
        raise ValueError('Official Chhattisgarh source identity differs')
    after = json.loads(before_body)
    results = []
    with fitz.open(source) as pdf:
        for record in after['records']:
            code = record['code']
            if code not in TARGETS:
                continue
            name, page, winner_votes, runner_votes = TARGETS[code]
            candidates = record['candidates']
            totals = record['turnout_totals']
            if (record['name'] != name or record['number_of_seats'] != 1
                    or record['status'] != 'needs_review' or record['source_warning_code'] != 'official_turnout_from_residual_source'
                    or record['error'] != NOTE or record.get('official_detail_result') is not None
                    or record['detail_page'] != page or record['turnout_source_page'] != page
                    or record['turnout_source_file'] != before['source_file']
                    or record['turnout_source_sha256'] != SOURCE_SHA256 or totals['source_page'] != page
                    or len(candidates) < 2 or [c['source_row'] for c in candidates] != list(range(1, len(candidates) + 1))
                    or {c['source_page'] for c in candidates} != {page}
                    or len({(c['candidate_name'].casefold(), c['party_at_election'].casefold()) for c in candidates}) != len(candidates)
                    or any(c['votes'] != c['general_votes'] + c['postal_votes'] for c in candidates)
                    or sum(c['votes'] for c in candidates) != record['votes_polled']
                    or sum(c['votes'] for c in candidates if not c['is_nota']) != record['valid_candidate_votes']
                    or sum(c['general_votes'] for c in candidates) != totals['general_votes']
                    or sum(c['postal_votes'] for c in candidates) != totals['postal_votes']):
                raise ValueError(f'Chhattisgarh candidate/turnout evidence differs: {code}')
            ranked = sorted((c for c in candidates if not c['is_nota']), key=lambda c: -c['votes'])
            winner, runner = ranked[:2]
            if (winner['votes'], runner['votes']) != (winner_votes, runner_votes):
                raise ValueError(f'Chhattisgarh result vote order differs: {code}')
            text = pdf[page - 1].get_text(sort=True)
            if name.split(' (')[0].casefold() not in text.casefold():
                raise ValueError(f'Chhattisgarh detailed page identity differs: {code}')
            for candidate in (winner, runner):
                pattern = (re.escape(candidate['candidate_name']).replace(r'\ ', r'\s+')
                           + r'.{0,250}\b' + str(candidate['votes']) + r'\b')
                if re.search(pattern, text, re.I | re.S) is None:
                    raise ValueError(f'Official Chhattisgarh PDF candidate differs: {code}')
            result = {'winner': winner['candidate_name'], 'winner_party': winner['party_at_election'],
                      'winner_votes': winner['votes'], 'runner': runner['candidate_name'],
                      'runner_party': runner['party_at_election'], 'runner_votes': runner['votes'],
                      'margin': winner['votes'] - runner['votes'], 'source_page': page,
                      'source_file': before['source_file'], 'source_sha256': SOURCE_SHA256}
            record['official_detail_result'] = result
            results.append({'code': code, 'name': name, **result})
    if {r['code'] for r in results} != set(TARGETS):
        raise ValueError('Chhattisgarh result coverage differs')
    for old, new in zip(before['records'], after['records']):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if changed != ({'official_detail_result'} if old['code'] in TARGETS else set()):
            raise ValueError(f'Unrelated Chhattisgarh extraction changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'scope': 'Chhattisgarh 2018 AC detailed results', 'edition': EDITION,
             'source_url': SOURCE_URL, 'source_sha256': SOURCE_SHA256,
             'previous_sha256': digest(before_body), 'new_sha256': digest(after_body), 'results': results}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-2018-chhattisgarh-', dir=output.parent) as temporary:
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
    output.with_suffix('.sha256').write_text(f'{digest(output.read_bytes())}  {output.name}\n', encoding='ascii')
    return {'bundle': str(output), 'sha256': digest(output.read_bytes()), **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
