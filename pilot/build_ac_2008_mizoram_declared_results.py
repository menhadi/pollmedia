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


CONFIG = SourceConfig(EDITION, NAME, PRIOR_SHA256, 'Mizoram', 40)


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT, config: SourceConfig = CONFIG) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / config.edition
    revisions = correction_index(root, PRIOR_PACKAGES)
    if len(revisions.get(config.edition, [])) != 2:
        raise ValueError(f'Prior {config.state} 2008 revisions are missing')
    old_body = effective_body(folder / 'extraction.json', revisions[config.edition])
    if digest(old_body) != config.prior_sha256:
        raise ValueError(f'Prior {config.state} 2008 extraction checksum differs')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    files = [row for row in manifest['files'] if row['file'] == old['source_file']]
    pdf_path = folder / old['source_file']
    if (old['kind'] != 'ac' or old['year'] != 2008 or len(old['records']) != config.seats
            or old['source_url'] != manifest['url'] or len(files) != 1
            or files[0]['sha256'] != old['source_sha256']
            or digest(pdf_path.read_bytes()) != old['source_sha256']):
        raise ValueError(f'Official {config.state} 2008 source identity differs')
    summaries = read_summary_pages(pdf_path)
    if len(summaries) != config.seats or set(summaries) != set(range(1, config.seats + 1)):
        raise ValueError(f'Official {config.state} 2008 summary coverage differs')
    revised = json.loads(old_body)
    results = []
    with fitz.open(pdf_path) as pdf:
        for record in revised['records']:
            summary = summaries[record['code']]
            if (record['status'] != 'needs_review' or record['state_name'] != config.state
                    or record['number_of_seats'] != 1
                    or record['source_warning_code'] != 'summary_turnout_with_detail_warnings'
                    or not record['error'].startswith(SOURCE_NOTE)
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
                    or int(heading[1]) != record['code']
                    or normalized(heading[2]) != normalized(record['name'])):
                raise ValueError(f'Official {config.state} declaration identity differs: {record["code"]}')
            winner_votes, runner_votes, margin_votes = int(winner[3]), int(runner[3]), int(margin[1])
            if (not 0 <= runner_votes < winner_votes <= summary['valid_candidate_votes']
                    or winner_votes - runner_votes != margin_votes
                    or not 0 < summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']):
                raise ValueError(f'Official {config.state} declaration arithmetic differs: {record["code"]}')
            result = {'winner': winner[2].strip(), 'winner_party': winner[1].strip(),
                      'winner_votes': winner_votes, 'runner': runner[2].strip(),
                      'runner_party': runner[1].strip(), 'runner_votes': runner_votes,
                      'margin': margin_votes}
            record['summary_source_file'] = old['source_file']
            record['summary_source_sha256'] = old['source_sha256']
            record['summary_result'] = result
            record['error'] += RESULT_NOTE
            results.append({'code': record['code'], 'page': summary['summary_page'], **result})
    if len(results) != config.seats or {item['code'] for item in results} != set(range(1, config.seats + 1)):
        raise ValueError(f'{config.state} 2008 result inventory differs')
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != {'summary_source_file', 'summary_source_sha256',
                                                          'summary_result', 'error'}:
            raise ValueError(f'Unrelated {config.state} evidence changed: {before["code"]}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': config.edition, 'year': 2008,
                                'source_url': old['source_url'], 'source_file': old['source_file'],
                                'source_sha256': old['source_sha256'], 'previous_sha256': digest(old_body),
                                'new_sha256': digest(new_body), 'results': results,
                                'prior_packages': list(PRIOR_PACKAGES)}


def build(root: Path = ROOT, config: SourceConfig = CONFIG) -> dict:
    output = root / 'exports' / (config.name + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root, config)
    old_sha = digest(old_body)
    with tempfile.TemporaryDirectory(prefix=f'ac-2008-{config.state.lower().replace(" ", "-")}-results-', dir=output.parent) as temporary:
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
            archive.writestr('AUDIT.json', json.dumps({'scope': f'{config.seats} reviewed 2008 {config.state} AC results',
                                                      **detail}, indent=2))
            archive.writestr('IMPORT.sh', import_script([config.edition]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'results': len(detail['results']),
            'previous_sha256': old_sha, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
