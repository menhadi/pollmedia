"""Add official Jharkhand 2014 results where the detail and summary elector counts differ."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_ac_2014_jharkhand_summary_only_results import (
    EDITION, PDF_SHA256, ROOT, SOURCE_URL, digest,
)
from build_pc_1992_summary_result_bundle import normalized
from build_pc_ac_zero_turnout_bundle import import_script
from extract_assembly_summary_totals import read_summary_pages
from preserve_archive_json import package


NAME = 'pollmedia-ac-2014-jharkhand-elector-difference-results-20261003'
PRIOR_RELEASE = 'pollmedia-election-corrections-20261003-wave19.zip'
PRIOR_RELEASE_SHA256 = 'a4572f8678e8518e371f594b62fa8f5c8e5e171e39e2c98cd67481dbc215018a'
PRIOR_SHA256 = 'bec3591ffc8c1e928bb959614ecbdcf71ed7a6bbfb1330ac850b2d5090cdfde1'
MARGIN_DIFFERENCE = {80: (21510, 21755)}
EXPECTED_CODES = {11, 14, 17, 21, 25, 27, 34, 54, 59, 63, 80}
RESULT_NOTE = ' Official summary declares the winner and margin; detailed candidate rows remain under review.'


def prior_body(root: Path) -> bytes:
    path = root / 'exports' / PRIOR_RELEASE
    if digest(path.read_bytes()) != PRIOR_RELEASE_SHA256:
        raise ValueError('Prior election release checksum differs')
    with zipfile.ZipFile(path) as release:
        bundle_name = 'pollmedia-ac-2014-jharkhand-summary-only-results-20261003.zip'
        sidecar = release.read(bundle_name.replace('.zip', '.sha256')).decode('ascii').split()[0]
        bundle = release.read(bundle_name)
        if digest(bundle) != sidecar:
            raise ValueError('Prior Jharkhand bundle checksum differs')
    with tempfile.TemporaryDirectory(prefix='jharkhand-prior-') as temporary:
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
    files = [item for item in manifest['files'] if item['file'] == old['source_file']]
    if (old['kind'] != 'ac' or old['year'] != 2014 or len(old['records']) != 81
            or old['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or old['source_sha256'] != PDF_SHA256 or len(files) != 1
            or files[0]['sha256'] != PDF_SHA256 or digest(pdf_path.read_bytes()) != PDF_SHA256):
        raise ValueError('Official Jharkhand 2014 source identity differs')
    summaries = read_summary_pages(pdf_path)
    revised = json.loads(old_body)
    targets = {r['code'] for r in revised['records']
               if r.get('source_warning_code') == 'official_summary_turnout_only'}
    if targets != EXPECTED_CODES or not targets <= set(summaries):
        raise ValueError('Elector-difference result inventory differs')
    results = []
    discrepancies = set()
    with fitz.open(pdf_path) as pdf:
        if 'JHARKHAND' not in pdf[0].get_text().upper() or '2014' not in pdf[0].get_text():
            raise ValueError('Official PDF cover identity differs')
        for record in revised['records']:
            code = record['code']
            if code not in targets:
                continue
            summary = summaries[code]
            difference = record.get('source_discrepancy') or {}
            totals = record.get('summary_totals') or {}
            if (record['status'] != 'needs_review' or record.get('state_name') != 'Jharkhand'
                    or record.get('number_of_seats', 1) != 1
                    or record.get('summary_result') is not None
                    or record.get('summary_source_file') != old['source_file']
                    or record.get('summary_source_sha256') != PDF_SHA256
                    or record.get('summary_page') != summary['summary_page']
                    or normalized(record['name']) != normalized(summary['name'])
                    or totals != {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
                    or difference.get('field') != 'electors'
                    or difference.get('detail_value') != record.get('electors')
                    or difference.get('summary_value') != summary['electors']
                    or record.get('votes_polled') != summary['votes_polled']):
                raise ValueError(f'Prior Jharkhand elector-difference record differs: {code}')
            text = pdf[summary['summary_page'] - 1].get_text(sort=True)
            identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
            winner = re.search(r'^\s*WINNER\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            runner = re.search(r'^\s*RUNN?ER-UP\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            margin = re.search(r'^\s*MARGIN\s+(\d+)\b', text, re.I | re.M)
            if (not all((identity, winner, runner, margin))
                    or 'legislative assembly of jharkhand' not in text.lower()
                    or int(identity[1]) != code or normalized(identity[2]) != normalized(record['name'])):
                raise ValueError(f'Official Jharkhand declaration differs: {code}')
            winner_votes, runner_votes, printed = int(winner[3]), int(runner[3]), int(margin[1])
            calculated = winner_votes - runner_votes
            if not 0 < runner_votes < winner_votes <= summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']:
                raise ValueError(f'Official Jharkhand result arithmetic differs: {code}')
            if printed != calculated:
                if MARGIN_DIFFERENCE.get(code) != (printed, calculated):
                    raise ValueError(f'Undocumented margin difference: {code}')
                discrepancies.add(code)
                record['result_margin_discrepancy'] = {
                    'field': 'margin', 'printed_value': printed,
                    'calculated_from_official_votes': calculated,
                    'summary_page': summary['summary_page'],
                }
            result = {'winner': winner[2].strip(), 'winner_party': winner[1].strip(),
                      'winner_votes': winner_votes, 'runner': runner[2].strip(),
                      'runner_party': runner[1].strip(), 'runner_votes': runner_votes,
                      'margin': calculated}
            record['summary_result'] = result
            record['error'] += RESULT_NOTE
            if printed != calculated:
                record['error'] += (f' The printed margin is {printed}, while official winner and runner-up '
                                    f'votes differ by {calculated}; the displayed margin uses that difference.')
            results.append({'code': code, 'page': summary['summary_page'],
                            'printed_margin': printed, **result})
    if len(results) != 11 or {r['code'] for r in results} != targets or discrepancies != set(MARGIN_DIFFERENCE):
        raise ValueError('Jharkhand official result coverage differs')
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        expected = {'summary_result', 'error'} if before['code'] in targets else set()
        if before['code'] in MARGIN_DIFFERENCE:
            expected.add('result_margin_discrepancy')
        if changed != expected:
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
    with tempfile.TemporaryDirectory(prefix='ac-2014-jharkhand-electors-', dir=output.parent) as temporary:
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
