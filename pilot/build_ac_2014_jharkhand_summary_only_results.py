"""Publish 45 Jharkhand 2014 declarations from the archived official summary pages."""

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
EDITION = '775e12dc77eb9f634ba9a490'
NAME = 'pollmedia-ac-2014-jharkhand-summary-only-results-20261003'
PRIOR_PACKAGES = ('pollmedia-ac-summary-corrections-20261001-v7.zip',
                  'pollmedia-pc-ac-zero-turnout-corrections-20261001-v3.zip',
                  'pollmedia-ac-residual-turnout-corrections-20261001-v4.zip')
PRIOR_SHA256 = '26e70f5a75fc30225621b66014130c33ccf395f857e691004b54b64b7032eea6'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3787-jharkhand-2014/'
PDF_SHA256 = 'c4ba5bd797b252430374ee6b2297c85d3eec62091b37b1d1c3ff8623f8383d94'
NOTE_PREFIX = 'Official summary confirms constituency turnout; detailed candidate rows remain unverified.'
RESULT_NOTE = ' Official summary declares the winner and margin; detailed candidate rows remain under review.'
PRINTED_MARGIN_DIFFERENCES = {10: (5262, 4914), 78: (5881, 5862)}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    revisions = correction_index(root, PRIOR_PACKAGES)
    if len(revisions.get(EDITION, [])) != 3:
        raise ValueError('Prior Jharkhand 2014 revision chain differs')
    old_body = effective_body(folder / 'extraction.json', revisions[EDITION])
    if digest(old_body) != PRIOR_SHA256:
        raise ValueError('Prior Jharkhand 2014 extraction checksum differs')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    pdf_path = folder / old['source_file']
    files = [item for item in manifest['files'] if item['file'] == old['source_file']]
    if (old['kind'] != 'ac' or old['year'] != 2014 or len(old['records']) != 81
            or old['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or old['source_sha256'] != PDF_SHA256 or len(files) != 1
            or files[0]['sha256'] != PDF_SHA256 or digest(pdf_path.read_bytes()) != PDF_SHA256):
        raise ValueError('Official Jharkhand 2014 source identity differs')
    summaries = read_summary_pages(pdf_path)
    if len(summaries) != 74:
        raise ValueError('Official Jharkhand 2014 summary coverage differs')
    revised = json.loads(old_body)
    results = []
    targets = {record['code'] for record in revised['records']
               if record.get('source_warning_code') == 'summary_only_turnout'}
    if len(targets) != 45 or not targets <= set(summaries):
        raise ValueError('Jharkhand 2014 summary-only seat inventory differs')
    seen_printed_differences = set()
    with fitz.open(pdf_path) as pdf:
        cover = pdf[0].get_text().upper()
        if 'JHARKHAND' not in cover or '2014' not in cover:
            raise ValueError('Official PDF cover does not identify Jharkhand 2014')
        for record in revised['records']:
            code = record['code']
            if code not in targets:
                continue
            summary = summaries[code]
            if (record['status'] != 'needs_review' or record.get('state_name') != 'Jharkhand'
                    or record.get('number_of_seats', 1) != 1
                    or not record.get('error', '').startswith(NOTE_PREFIX)
                    or record.get('summary_result') is not None
                    or record.get('summary_source_file') is not None
                    or record.get('summary_source_sha256') is not None
                    or record.get('source_discrepancy') is not None
                    or record.get('summary_page') != summary['summary_page']
                    or record.get('summary_totals') != {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes', 'nota_votes')}
                    or record.get('electors') != summary['electors']
                    or record.get('votes_polled') != summary['votes_polled']
                    or normalized(record['name']) != normalized(summary['name'])):
                raise ValueError(f'Prior reviewed Jharkhand constituency differs: {code}')
            text = pdf[summary['summary_page'] - 1].get_text(sort=True)
            identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
            winner = re.search(r'^\s*WINNER\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            runner = re.search(r'^\s*RUNN?ER-UP\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            margin = re.search(r'^\s*MARGIN\s+(\d+)\b', text, re.I | re.M)
            if (not all((identity, winner, runner, margin))
                    or 'legislative assembly of jharkhand' not in text.lower()
                    or '2014' not in text[:220]
                    or int(identity[1]) != code
                    or normalized(identity[2]) != normalized(record['name'])):
                raise ValueError(f'Official Jharkhand declaration identity differs: {code}')
            winner_votes, runner_votes, printed_margin = int(winner[3]), int(runner[3]), int(margin[1])
            computed_margin = winner_votes - runner_votes
            if (not 0 < runner_votes < winner_votes <= summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']
                    or printed_margin <= 0):
                raise ValueError(f'Official Jharkhand result arithmetic differs: {code}')
            if printed_margin != computed_margin:
                if PRINTED_MARGIN_DIFFERENCES.get(code) != (printed_margin, computed_margin):
                    raise ValueError(f'Undocumented official margin difference: {code}')
                seen_printed_differences.add(code)
            result = {'winner': winner[2].strip(), 'winner_party': winner[1].strip(),
                      'winner_votes': winner_votes, 'runner': runner[2].strip(),
                      'runner_party': runner[1].strip(), 'runner_votes': runner_votes,
                      'margin': computed_margin}
            record['summary_source_file'] = old['source_file']
            record['summary_source_sha256'] = PDF_SHA256
            record['summary_result'] = result
            record['error'] += RESULT_NOTE
            if printed_margin != computed_margin:
                record['source_discrepancy'] = {'field': 'margin', 'printed_value': printed_margin,
                                                'calculated_from_official_votes': computed_margin,
                                                'summary_page': summary['summary_page']}
                record['error'] += (f' The printed margin is {printed_margin}, while official winner and runner-up '
                                    f'votes differ by {computed_margin}; the displayed margin uses that difference.')
            results.append({'code': code, 'page': summary['summary_page'],
                            'printed_margin': printed_margin, **result})
    if len(results) != 45 or {item['code'] for item in results} != targets:
        raise ValueError('Jharkhand 2014 declared-result inventory differs')
    if seen_printed_differences != set(PRINTED_MARGIN_DIFFERENCES):
        raise ValueError('Jharkhand 2014 printed-margin discrepancy inventory differs')
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        expected = {'summary_source_file', 'summary_source_sha256', 'summary_result', 'error'} if before['code'] in targets else set()
        if before['code'] in PRINTED_MARGIN_DIFFERENCES:
            expected.add('source_discrepancy')
        if changed != expected:
            raise ValueError(f'Unrelated Jharkhand 2014 evidence changed: {before["code"]}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    detail = {'edition': EDITION, 'year': 2014, 'state': 'Jharkhand',
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
    with tempfile.TemporaryDirectory(prefix='ac-2014-jharkhand-results-', dir=output.parent) as temporary:
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
