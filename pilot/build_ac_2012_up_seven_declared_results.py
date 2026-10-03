"""Add seven Uttar Pradesh 2012 declarations from the archived ECI summaries."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from audit_pc_ac_zero_values import correction_index, effective_body
from build_pc_1992_summary_result_bundle import normalized
from build_pc_ac_zero_turnout_bundle import import_script
from extract_assembly_summary_totals import read_summary_pages
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = 'f4df4876a829786cd04cb280'
NAME = 'pollmedia-ac-2012-up-seven-declared-results-20261003'
PRIOR_PACKAGE = 'pollmedia-pc-ac-zero-turnout-corrections-20261001-v3.zip'
PRIOR_SHA256 = '1f3f37c7c8514bb1e3161b0947534432e29795eb15068b807af07d2ba8a6cd48'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3262-uttar-pradesh-2012/'
PDF_SHA256 = 'ea379cf64ca37b500f9af32b0587d9edc24145e87073fac271b08b1016560a6c'
TARGET_CODES = frozenset({6, 59, 108, 110, 236, 283, 318})
EXISTING_NOTE = 'Official constituency summary supplies electors and voters; detailed candidate rows remain under review.'
RESULT_NOTE = ' Official summary declares the winner and margin; detailed candidate totals still need review.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    revisions = correction_index(root, [PRIOR_PACKAGE])
    if len(revisions.get(EDITION, [])) != 1:
        raise ValueError('Prior Uttar Pradesh 2012 correction missing')
    old_body = effective_body(folder / 'extraction.json', revisions[EDITION])
    if digest(old_body) != PRIOR_SHA256:
        raise ValueError('Prior Uttar Pradesh 2012 checksum differs')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    pdf_path = folder / old['source_file']
    files = [item for item in manifest['files'] if item['file'] == old['source_file']]
    if (old['kind'] != 'ac' or old['year'] != 2012 or len(old['records']) != 403
            or old['validated_count'] != 396 or old['review_count'] != 7
            or old['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or old['source_sha256'] != PDF_SHA256 or len(files) != 1
            or files[0]['sha256'] != PDF_SHA256 or digest(pdf_path.read_bytes()) != PDF_SHA256):
        raise ValueError('Official Uttar Pradesh 2012 source identity differs')
    summaries = read_summary_pages(pdf_path)
    if set(summaries) != set(range(1, 404)):
        raise ValueError('Official Uttar Pradesh 2012 summary coverage differs')
    revised = json.loads(old_body)
    results = []
    with fitz.open(pdf_path) as pdf:
        cover = pdf[0].get_text().upper()
        if 'UTTAR PRADESH' not in cover or '2012' not in cover:
            raise ValueError('Official PDF cover does not identify Uttar Pradesh 2012')
        for record in revised['records']:
            code = record['code']
            if code not in TARGET_CODES:
                continue
            summary = summaries[code]
            if (record['status'] != 'needs_review'
                    or record.get('source_warning_code') != 'official_summary_turnout_only'
                    or record.get('error') != EXISTING_NOTE
                    or record.get('summary_result') is not None
                    or record.get('summary_page') != summary['summary_page']
                    or record.get('summary_totals') != {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
                    or record.get('summary_source_file') != old['source_file']
                    or record.get('summary_source_sha256') != PDF_SHA256
                    or record.get('electors') != summary['electors']
                    or record.get('votes_polled') != summary['votes_polled']
                    or normalized(record['name']) != normalized(summary['name'])
                    or len(record.get('candidates') or []) < 2):
                raise ValueError(f'Prior reviewed constituency differs: {code}')
            text = pdf[summary['summary_page'] - 1].get_text(sort=True)
            identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
            winner = re.search(r'^\s*WINNER\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            runner = re.search(r'^\s*RUNN?ER-UP\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            margin = re.search(r'^\s*MARGIN\s+(\d+)\b', text, re.I | re.M)
            if (not all((identity, winner, runner, margin))
                    or 'legislative assembly of Uttar Pradesh' not in text
                    or '2012' not in text[:220]
                    or int(identity[1]) != code
                    or normalized(identity[2]) != normalized(record['name'])):
                raise ValueError(f'Official declaration identity differs: {code}')
            first, second = record['candidates'][:2]
            winner_votes, runner_votes, printed_margin = int(winner[3]), int(runner[3]), int(margin[1])
            if (normalized(first['candidate_name']) != normalized(winner[2])
                    or normalized(second['candidate_name']) != normalized(runner[2])
                    or first['party_at_election'] != winner[1]
                    or second['party_at_election'] != runner[1]
                    or first['votes'] != winner_votes or second['votes'] != runner_votes
                    or printed_margin != winner_votes - runner_votes
                    or not 0 < runner_votes < winner_votes <= summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']):
                raise ValueError(f'Official result does not reconcile: {code}')
            result = {'winner': winner[2].strip(), 'winner_party': winner[1].strip(),
                      'winner_votes': winner_votes, 'runner': runner[2].strip(),
                      'runner_party': runner[1].strip(), 'runner_votes': runner_votes,
                      'margin': printed_margin}
            record['summary_result'] = result
            record['error'] += RESULT_NOTE
            results.append({'code': code, 'page': summary['summary_page'], **result})
    if {item['code'] for item in results} != TARGET_CODES or len(results) != len(TARGET_CODES):
        raise ValueError('Seven-seat official declaration inventory differs')
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if changed != ({'summary_result', 'error'} if before['code'] in TARGET_CODES else set()):
            raise ValueError(f'Unrelated evidence changed: {before["code"]}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    detail = {'edition': EDITION, 'year': 2012, 'state': 'Uttar Pradesh',
              'source_url': SOURCE_URL, 'source_file': old['source_file'],
              'source_sha256': PDF_SHA256, 'previous_sha256': digest(old_body),
              'new_sha256': digest(new_body), 'results': results,
              'prior_package': PRIOR_PACKAGE}
    return old_body, new_body, detail


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root)
    old_sha = digest(old_body)
    with tempfile.TemporaryDirectory(prefix='ac-2012-up-results-', dir=output.parent) as temporary:
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
    return {'bundle': str(output), 'sha256': sha, 'results': len(detail['results']),
            'previous_sha256': old_sha, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
