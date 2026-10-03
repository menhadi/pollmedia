"""Restore Mizoram 2008 AC declarations from the archived official summaries."""

import hashlib
import json
from dataclasses import dataclass
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
EDITION = '51a30ea845222a74460e8585'
NAME = 'pollmedia-ac-2008-mizoram-declared-results-20261003'
PRIOR_PACKAGES = tuple(f'pollmedia-ac-summary-corrections-20261001-v{i}.zip' for i in range(2, 8)) + tuple(
    f'pollmedia-pc-ac-zero-turnout-corrections-20261001-v{i}.zip' for i in range(1, 4))
PRIOR_SHA256 = '90e09ce95a3a9cee2e6aaed1db3d7b6510e767d5b0a732cfc5815b83f9b80154'
SOURCE_NOTE = 'Official summary confirms constituency turnout and candidate-vote total; detailed candidate text still needs review.'
RESULT_NOTE = ' Official summary declares the winner and margin.'


@dataclass(frozen=True)
class SourceConfig:
    edition: str
    name: str
    prior_sha256: str
    state: str
    seats: int
    pdf_state: str | None = None
    prior_packages: tuple[str, ...] | None = None
    expected_revisions: int = 2
    summary_only_codes: frozenset[int] = frozenset()
    year: int = 2008
    margin_discrepancies: tuple[tuple[int, int, int], ...] = ()
    uncontested_codes: frozenset[int] = frozenset()


CONFIG = SourceConfig(EDITION, NAME, PRIOR_SHA256, 'Mizoram', 40)


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT, config: SourceConfig = CONFIG) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / config.edition
    packages = config.prior_packages or PRIOR_PACKAGES
    revisions = correction_index(root, packages)
    if len(revisions.get(config.edition, [])) != config.expected_revisions:
        raise ValueError(f'Prior {config.state} 2008 revisions are missing')
    old_body = effective_body(folder / 'extraction.json', revisions[config.edition])
    if digest(old_body) != config.prior_sha256:
        raise ValueError(f'Prior {config.state} 2008 extraction checksum differs')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    files = [row for row in manifest['files'] if row['file'] == old['source_file']]
    pdf_path = folder / old['source_file']
    if (old['kind'] != 'ac' or old['year'] != config.year or len(old['records']) != config.seats
            or old['source_url'] != manifest['url'] or len(files) != 1
            or files[0]['sha256'] != old['source_sha256']
            or digest(pdf_path.read_bytes()) != old['source_sha256']):
        raise ValueError(f'Official {config.state} 2008 source identity differs')
    summaries = read_summary_pages(pdf_path)
    contested_codes = set(range(1, config.seats + 1)) - config.uncontested_codes
    if len(summaries) != len(contested_codes) or set(summaries) != contested_codes:
        raise ValueError(f'Official {config.state} 2008 summary coverage differs')
    revised = json.loads(old_body)
    results = []
    seen_margin_discrepancies = set()
    with fitz.open(pdf_path) as pdf:
        for record in revised['records']:
            if record['code'] in config.uncontested_codes:
                if (record['status'] != 'needs_review' or record['state_name'] != config.state
                        or record['number_of_seats'] != 1 or record.get('summary_result') is not None
                        or record.get('summary_page') is not None
                        or record.get('votes_polled') not in (None, 0)
                        or record.get('margin') not in (None, 0)
                        or len(record['candidates']) != 1
                        or record['candidates'][0].get('votes') not in (None, 0)):
                    raise ValueError(f'Uncontested {config.state} source row differs: {record["code"]}')
                continue
            summary = summaries[record['code']]
            summary_only = record['code'] in config.summary_only_codes
            if (record['status'] != 'needs_review' or record['state_name'] != config.state
                    or record['number_of_seats'] != 1
                    or record['source_warning_code'] != ('summary_only_turnout' if summary_only else 'summary_turnout_with_detail_warnings')
                    or not record['error'].startswith('Official summary confirms constituency turnout;' if summary_only else SOURCE_NOTE)
                    or record['summary_page'] != summary['summary_page']
                    or normalized(record['name']) != normalized(summary['name'])
                    or record['summary_totals'] != {k: summary[k] for k in ('electors', 'votes_polled', 'valid_candidate_votes')}
                    or record['electors'] != summary['electors']
                    or record['votes_polled'] != summary['votes_polled']
                    or record.get('summary_result') is not None
                    or record.get('summary_source_file') is not None):
                raise ValueError(f'Reviewed {config.state} constituency differs: {record["code"]}')
            text = pdf[summary['summary_page'] - 1].get_text(sort=True)
            heading = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
            winner = re.search(r'^\s*WINNER\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            runner = re.search(r'^\s*RUNNER-UP\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            margin = re.search(r'^\s*MARGIN\s+(\d+)\b', text, re.I | re.M)
            if (not all((heading, winner, runner, margin))
                    or f'legislative assembly of {config.pdf_state or config.state}' not in text
                    or str(config.year) not in text[:220]
                    or int(heading[1]) != record['code']
                    or normalized(heading[2]) != normalized(record['name'])):
                raise ValueError(f'Official {config.state} declaration identity differs: {record["code"]}')
            winner_votes, runner_votes, margin_votes = int(winner[3]), int(runner[3]), int(margin[1])
            calculated_margin = winner_votes - runner_votes
            if (not 0 <= runner_votes < winner_votes <= summary['valid_candidate_votes']
                    or not 0 < summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']):
                raise ValueError(f'Official {config.state} declaration arithmetic differs: {record["code"]}')
            if margin_votes != calculated_margin:
                discrepancy = (record['code'], margin_votes, calculated_margin)
                if discrepancy not in config.margin_discrepancies:
                    raise ValueError(f'Undocumented official margin difference: {record["code"]}')
                seen_margin_discrepancies.add(discrepancy)
            result = {'winner': winner[2].strip(), 'winner_party': winner[1].strip(),
                      'winner_votes': winner_votes, 'runner': runner[2].strip(),
                      'runner_party': runner[1].strip(), 'runner_votes': runner_votes,
                      'margin': calculated_margin}
            record['summary_source_file'] = old['source_file']
            record['summary_source_sha256'] = old['source_sha256']
            record['summary_result'] = result
            if summary_only:
                record['original_source_warning_code'] = record['source_warning_code']
                record['source_warning_code'] = 'official_summary_turnout_only'
            record['error'] += RESULT_NOTE
            if margin_votes != calculated_margin:
                record['official_printed_margin'] = margin_votes
                record['source_discrepancy'] = {'field': 'margin', 'printed_value': margin_votes,
                                                'calculated_from_official_votes': calculated_margin,
                                                'summary_page': summary['summary_page']}
                record['error'] += (f' The printed margin is {margin_votes}, while the official winner and runner-up '
                                    f'votes differ by {calculated_margin}; the displayed margin uses that difference.')
            results.append({'code': record['code'], 'page': summary['summary_page'], **result})
    if len(results) != len(contested_codes) or {item['code'] for item in results} != contested_codes:
        raise ValueError(f'{config.state} 2008 result inventory differs')
    if seen_margin_discrepancies != set(config.margin_discrepancies):
        raise ValueError(f'{config.state} expected margin discrepancies differ')
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        expected = {'summary_source_file', 'summary_source_sha256', 'summary_result', 'error'}
        if before['code'] in config.uncontested_codes:
            expected = set()
        if before['code'] in config.summary_only_codes:
            expected |= {'source_warning_code', 'original_source_warning_code'}
        if before['code'] in {item[0] for item in config.margin_discrepancies}:
            expected |= {'official_printed_margin', 'source_discrepancy'}
        if before['code'] != after['code'] or changed != expected:
            raise ValueError(f'Unrelated {config.state} evidence changed: {before["code"]}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': config.edition, 'year': config.year,
                                'source_url': old['source_url'], 'source_file': old['source_file'],
                                'source_sha256': old['source_sha256'], 'previous_sha256': digest(old_body),
                                'new_sha256': digest(new_body), 'results': results,
                                'prior_packages': list(packages)}


def build(root: Path = ROOT, config: SourceConfig = CONFIG) -> dict:
    output = root / 'exports' / (config.name + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root, config)
    old_sha = digest(old_body)
    with tempfile.TemporaryDirectory(prefix=f'ac-{config.year}-{config.state.lower().replace(" ", "-")}-results-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        inner = []
        for kind, relative, body in (
                ('snapshot', f'election-archive/{config.edition}/extraction-{old_sha}.json', old_body),
                ('correction', f'election-archive/{config.edition}/extraction.json', new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{config.edition}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    old_sha if kind == 'correction' else None,
                    f'election-archive/{config.edition}/extraction-{old_sha}.json' if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', config.edition + '\n')
            archive.writestr('AUDIT.json', json.dumps({'scope': f'{config.seats} reviewed {config.year} {config.state} AC results',
                                                      **detail}, indent=2))
            archive.writestr('IMPORT.sh', import_script([config.edition]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'results': len(detail['results']),
            'previous_sha256': old_sha, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
