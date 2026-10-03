"""Add Satyavedu's source-backed 2014 result from its archived detailed PDF page."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from audit_pc_ac_zero_values import correction_index, effective_body
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '7a8ef9c9247cb626f6a03998'
NAME = 'pollmedia-ac-2014-andhra-satyavedu-detail-result-20261003'
PRIOR_PACKAGES = ('pollmedia-ac-summary-corrections-20261001-v5.zip',
                  'pollmedia-ac-residual-turnout-corrections-20261001-v4.zip')
PRIOR_SHA256 = '2ea0c623ec85c71fb9cbc21502586e6b585710f56f52c98ca52ad9a79f45d176'
SOURCE_URL = 'https://old.eci.gov.in/files/file/4055-andhra-pradesh-2014/'
PDF_SHA256 = '8f9aaf7a05a173620c3be4ad21cf84416f0df2c17fafb542a69af8f7b0cde941'
EXISTING_NOTE = 'Official source prints the constituency turnout total; previous candidate/source warnings remain available for review.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    revisions = correction_index(root, PRIOR_PACKAGES)
    if len(revisions.get(EDITION, [])) != 2:
        raise ValueError('Prior Andhra Pradesh 2014 revision chain differs')
    old_body = effective_body(folder / 'extraction.json', revisions[EDITION])
    if digest(old_body) != PRIOR_SHA256:
        raise ValueError('Prior Andhra Pradesh 2014 extraction checksum differs')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    pdf_path = folder / old['source_file']
    files = [item for item in manifest['files'] if item['file'] == old['source_file']]
    if (old['kind'] != 'ac' or old['year'] != 2014 or len(old['records']) != 294
            or old['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or old['source_sha256'] != PDF_SHA256 or len(files) != 1
            or files[0]['sha256'] != PDF_SHA256 or digest(pdf_path.read_bytes()) != PDF_SHA256):
        raise ValueError('Official Andhra Pradesh 2014 source identity differs')
    revised = json.loads(old_body)
    results = []
    with fitz.open(pdf_path) as pdf:
        cover = pdf[0].get_text().upper()
        if 'ANDHRA PRADESH' not in cover or '2014' not in cover:
            raise ValueError('Official PDF cover does not identify Andhra Pradesh 2014')
        for record in revised['records']:
            if record['code'] != 288:
                continue
            totals = record.get('turnout_totals') or {}
            candidates = record.get('candidates') or []
            if (record['status'] != 'needs_review' or record.get('state_name') != 'Andhra Pradesh'
                    or record.get('name') != 'Satyavedu (SC)' or record.get('number_of_seats') != 1
                    or record.get('source_warning_code') != 'official_turnout_from_residual_source'
                    or record.get('error') != EXISTING_NOTE
                    or record.get('official_detail_result') is not None
                    or record.get('detail_page') != 489 or record.get('turnout_source_page') != 489
                    or record.get('turnout_source_file') != old['source_file']
                    or record.get('turnout_source_sha256') != PDF_SHA256
                    or record.get('electors') != 193718 or record.get('votes_polled') != 161036
                    or record.get('valid_candidate_votes') != 160145
                    or totals != {'electors': 193718, 'votes_polled': 161036,
                                  'general_votes': 160646, 'postal_votes': 390,
                                  'source_turnout_percent': 83.13,
                                  'source_page': 489, 'method': 'official detailed turnout row'}
                    or len(candidates) != 13):
                raise ValueError('Prior reviewed Satyavedu source row differs')
            total = valid = general = postal = 0
            seen = set()
            for index, candidate in enumerate(candidates, 1):
                identity = (candidate['candidate_name'].casefold(), candidate['party_at_election'].casefold())
                if (candidate.get('source_row') != index or candidate.get('source_page') != 489
                        or identity in seen or candidate['votes'] != candidate['general_votes'] + candidate['postal_votes']):
                    raise ValueError('Satyavedu candidate source row differs')
                seen.add(identity)
                total += candidate['votes']
                general += candidate['general_votes']
                postal += candidate['postal_votes']
                if candidate.get('is_nota') is not True:
                    valid += candidate['votes']
            ranked = sorted((candidate for candidate in candidates if candidate.get('is_nota') is not True),
                            key=lambda candidate: candidate['votes'], reverse=True)
            winner, runner = ranked[:2]
            if (total, valid, general, postal) != (161036, 160145, 160646, 390):
                raise ValueError('Satyavedu official candidate and turnout totals differ')
            if ((winner['candidate_name'], winner['party_at_election'], winner['votes'])
                    != ('TALARI ADITYA', 'TDP', 77655)
                    or (runner['candidate_name'], runner['party_at_election'], runner['votes'])
                    != ('K.ADIMULAM', 'YSRCP', 73428)):
                raise ValueError('Satyavedu candidate ranking differs')
            text = pdf[488].get_text(sort=True)
            block = re.search(r'Constituency\s+288\.\s+Satyavedu\s*\(SC\).*?(?=Constituency\s+289\.|\Z)',
                              text, re.I | re.S)
            if block is None or not re.search(r'TOTAL ELECTORS\s*:\s*193718', block[0]):
                raise ValueError('Official Satyavedu PDF identity differs')
            if not re.search(r'TURNOUT\s+TOTAL:\s+160646\s+390\s+161036\s+83\.13', block[0]):
                raise ValueError('Official Satyavedu PDF turnout differs')
            for candidate in (winner, runner):
                name_pattern = re.escape(candidate['candidate_name']).replace(r'\ ', r'\s+')
                if not re.search(name_pattern + r'.*?\b' + str(candidate['votes']) + r'\b', block[0], re.I):
                    raise ValueError('Official Satyavedu PDF candidate result differs')
            result = {'winner': winner['candidate_name'], 'winner_party': winner['party_at_election'],
                      'winner_votes': winner['votes'], 'runner': runner['candidate_name'],
                      'runner_party': runner['party_at_election'], 'runner_votes': runner['votes'],
                      'margin': winner['votes'] - runner['votes'], 'source_page': 489,
                      'source_file': old['source_file'], 'source_sha256': PDF_SHA256}
            record['official_detail_result'] = result
            results.append({'code': 288, **result})
    if len(results) != 1:
        raise ValueError('Satyavedu result inventory differs')
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if changed != ({'official_detail_result'} if before['code'] == 288 else set()):
            raise ValueError(f'Unrelated Andhra Pradesh 2014 evidence changed: {before["code"]}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    detail = {'edition': EDITION, 'year': 2014, 'state': 'Andhra Pradesh',
              'source_url': SOURCE_URL, 'source_file': old['source_file'],
              'source_sha256': PDF_SHA256, 'previous_sha256': digest(old_body),
              'new_sha256': digest(new_body), 'results': results,
              'prior_packages': list(PRIOR_PACKAGES)}
    return old_body, new_body, detail


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root)
    old_sha = digest(old_body)
    with tempfile.TemporaryDirectory(prefix='ac-2014-andhra-satyavedu-', dir=output.parent) as temporary:
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
            archive.writestr('AUDIT.json', json.dumps(detail, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'results': 1,
            'previous_sha256': old_sha, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
