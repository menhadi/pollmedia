"""Publish two complete Jharkhand 2014 AC results from single detailed PDF pages."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_ac_2014_jharkhand_summary_only_results import EDITION, PDF_SHA256, ROOT, SOURCE_URL, digest
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


NAME = 'pollmedia-ac-2014-jharkhand-single-page-detail-results-20261003'
PRIOR_RELEASE = 'pollmedia-election-corrections-20261003-wave20.zip'
PRIOR_RELEASE_SHA256 = '4adfdf0cd327f5ebc9f7e9b88b530d16892fce27446451da996ac82630fd3839'
PRIOR_SHA256 = '8ff23f5e9256ce006e14c5764fa83598660341d20f6bd280573dc0829fc80ea8'
TARGETS = {43: ('Baghmara', 119, 86603, 56980),
           55: ('Manoharpur (ST)', 125, 57558, 40989)}
EXISTING_NOTE = 'Official source prints the constituency turnout total; previous candidate/source warnings remain available for review.'


def prior_body(root: Path) -> bytes:
    release_path = root / 'exports' / PRIOR_RELEASE
    if digest(release_path.read_bytes()) != PRIOR_RELEASE_SHA256:
        raise ValueError('Prior election release checksum differs')
    with zipfile.ZipFile(release_path) as release:
        bundle_name = 'pollmedia-ac-2014-jharkhand-elector-difference-results-20261003.zip'
        bundle = release.read(bundle_name)
        expected = release.read(bundle_name.replace('.zip', '.sha256')).decode('ascii').split()[0]
        if digest(bundle) != expected:
            raise ValueError('Prior Jharkhand bundle checksum differs')
    with tempfile.TemporaryDirectory(prefix='jharkhand-detail-prior-') as temporary:
        bundle_path = Path(temporary) / bundle_name
        bundle_path.write_bytes(bundle)
        with zipfile.ZipFile(bundle_path) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            if audit['new_sha256'] != PRIOR_SHA256:
                raise ValueError('Prior Jharkhand revision differs')
            inner_path = Path(temporary) / f'correction-{EDITION}.zip'
            inner_path.write_bytes(outer.read(inner_path.name))
            with zipfile.ZipFile(inner_path) as inner:
                body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if digest(body) != PRIOR_SHA256:
        raise ValueError('Prior Jharkhand extraction checksum differs')
    return body


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    old_body = prior_body(root)
    old = json.loads(old_body)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    pdf_path = folder / old['source_file']
    matching = [item for item in manifest['files'] if item['file'] == old['source_file']]
    if (old['kind'] != 'ac' or old['year'] != 2014 or len(old['records']) != 81
            or old['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or old['source_sha256'] != PDF_SHA256 or len(matching) != 1
            or matching[0]['sha256'] != PDF_SHA256 or digest(pdf_path.read_bytes()) != PDF_SHA256):
        raise ValueError('Official Jharkhand 2014 source identity differs')
    revised = json.loads(old_body)
    results = []
    with fitz.open(pdf_path) as pdf:
        if 'JHARKHAND' not in pdf[0].get_text().upper() or '2014' not in pdf[0].get_text():
            raise ValueError('Official PDF cover identity differs')
        for record in revised['records']:
            code = record['code']
            if code not in TARGETS:
                continue
            name, page, winner_votes, runner_votes = TARGETS[code]
            totals = record.get('turnout_totals') or {}
            candidates = record.get('candidates') or []
            if (record.get('status') != 'needs_review' or record.get('state_name') != 'Jharkhand'
                    or record.get('name') != name or record.get('number_of_seats') != 1
                    or record.get('source_warning_code') != 'official_turnout_from_residual_source'
                    or record.get('error') != EXISTING_NOTE or record.get('official_detail_result') is not None
                    or record.get('detail_page') != page or record.get('turnout_source_page') != page
                    or record.get('turnout_source_file') != old['source_file']
                    or record.get('turnout_source_sha256') != PDF_SHA256
                    or totals.get('source_page') != page or len(candidates) < 2):
                raise ValueError(f'Prior reviewed Jharkhand detailed record differs: {code}')
            total = valid = general = postal = 0
            seen = set()
            for index, candidate in enumerate(candidates, 1):
                identity = (candidate['candidate_name'].casefold(), candidate['party_at_election'].casefold())
                if (candidate.get('source_row') != index or candidate.get('source_page') != page
                        or identity in seen or candidate['votes'] != candidate['general_votes'] + candidate['postal_votes']):
                    raise ValueError(f'Jharkhand candidate source row differs: {code}')
                seen.add(identity)
                total += candidate['votes']
                general += candidate['general_votes']
                postal += candidate['postal_votes']
                if candidate.get('is_nota') is not True:
                    valid += candidate['votes']
            ranked = sorted((candidate for candidate in candidates if candidate.get('is_nota') is not True),
                            key=lambda candidate: candidate['votes'], reverse=True)
            winner, runner = ranked[:2]
            if (total != record['votes_polled'] or valid != record['valid_candidate_votes']
                    or general != totals['general_votes'] or postal != totals['postal_votes']
                    or (winner['votes'], runner['votes']) != (winner_votes, runner_votes)
                    or total != totals['votes_polled'] or total > totals['electors']):
                raise ValueError(f'Jharkhand candidate/turnout totals differ: {code}')
            text = pdf[page - 1].get_text(sort=True)
            block = re.search(r'Constituency\s+' + str(code) + r'\.\s+.*?(?=Constituency\s+\d+\.|\Z)',
                              text, re.I | re.S)
            if block is None or not re.search(r'TOTAL ELECTORS\s*:\s*' + str(totals['electors']), block[0]):
                raise ValueError(f'Official Jharkhand detailed page identity differs: {code}')
            for candidate in (winner, runner):
                pattern = re.escape(candidate['candidate_name']).replace(r'\ ', r'\s+')
                if not re.search(pattern + r'.*?\b' + str(candidate['votes']) + r'\b', block[0], re.I):
                    raise ValueError(f'Official Jharkhand candidate declaration differs: {code}')
            if not re.search(r'TURNOUT\s+TOTAL:\s+' + str(general) + r'\s+' + str(postal)
                             + r'\s+' + str(total) + r'\b', block[0]):
                raise ValueError(f'Official Jharkhand detailed turnout differs: {code}')
            result = {'winner': winner['candidate_name'], 'winner_party': winner['party_at_election'],
                      'winner_votes': winner['votes'], 'runner': runner['candidate_name'],
                      'runner_party': runner['party_at_election'], 'runner_votes': runner['votes'],
                      'margin': winner['votes'] - runner['votes'], 'source_page': page,
                      'source_file': old['source_file'], 'source_sha256': PDF_SHA256}
            record['official_detail_result'] = result
            results.append({'code': code, **result})
    if {item['code'] for item in results} != set(TARGETS):
        raise ValueError('Jharkhand detailed result inventory differs')
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if changed != ({'official_detail_result'} if before['code'] in TARGETS else set()):
            raise ValueError(f'Unrelated Jharkhand evidence changed: {before["code"]}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'edition': EDITION, 'year': 2014, 'state': 'Jharkhand',
             'source_url': SOURCE_URL, 'source_file': old['source_file'],
             'source_sha256': PDF_SHA256, 'previous_sha256': digest(old_body),
             'new_sha256': digest(new_body), 'results': results,
             'prior_release': PRIOR_RELEASE}
    return old_body, new_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, audit = revised_edition(root)
    old_sha = digest(old_body)
    with tempfile.TemporaryDirectory(prefix='ac-2014-jharkhand-detail-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        inner = []
        for kind, relative, body in (
                ('snapshot', f'election-archive/{EDITION}/extraction-{old_sha}.json', old_body),
                ('correction', f'election-archive/{EDITION}/extraction.json', new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    old_sha if kind == 'correction' else None,
                    f'election-archive/{EDITION}/extraction-{old_sha}.json' if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps(audit, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'results': len(audit['results']),
            'previous_sha256': old_sha, 'new_sha256': audit['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
