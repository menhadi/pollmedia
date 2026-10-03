"""Add 2014 Haryana and Jammu & Kashmir official AC declarations with review notes."""

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
PRIOR_PACKAGES = ('pollmedia-ac-summary-corrections-20261001-v5.zip',
                  'pollmedia-pc-ac-zero-turnout-corrections-20261001-v3.zip')
NOTE_PREFIX = 'Official constituency summary supplies electors and voters; detailed candidate rows remain under review.'
RESULT_NOTE = ' Official summary declares the winner and margin; detailed candidate totals still need review.'


@dataclass(frozen=True)
class Source:
    state: str
    slug: str
    edition: str
    seats: int
    prior_sha256: str
    pdf_sha256: str
    url: str
    codes: frozenset[int]


SOURCES = (
    Source('Jammu & Kashmir', 'jammu-kashmir', 'c7e2b7eb4781f4fee9dbeca3', 87,
           'eaa21b815858deef2cb01a0a64bc883e5134501f48869e9749bb4b7c347d0b1e',
           'ea0adecbee1a7b8481e9df736517cb82e5e19dbb27bd4dd560be360b04e305b0',
           'https://old.eci.gov.in/files/file/3797-jammu-kashmir-2014/',
           frozenset({5})),
    Source('Haryana', 'haryana', 'd34e828e1f0e22a96693d1c0', 90,
           '87a358d153e1033611ac757813a8bb76ba542bc61b1884a72dc737a1b337c5c0',
           '446d2ba1038c8dbf52cf6e1805f47184e4cab437438569961dedc38f20ba192b',
           'https://old.eci.gov.in/files/file/3827-haryana-2014/',
           frozenset({47, 48, 53})),
)


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(source: Source, root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / source.edition
    revisions = correction_index(root, PRIOR_PACKAGES)
    if len(revisions.get(source.edition, [])) != 2:
        raise ValueError(f'Prior {source.state} 2014 revision chain differs')
    old_body = effective_body(folder / 'extraction.json', revisions[source.edition])
    if digest(old_body) != source.prior_sha256:
        raise ValueError(f'Prior {source.state} 2014 extraction checksum differs')
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    pdf_path = folder / old['source_file']
    files = [item for item in manifest['files'] if item['file'] == old['source_file']]
    if (old['kind'] != 'ac' or old['year'] != 2014 or len(old['records']) != source.seats
            or old['source_url'] != source.url or manifest['url'] != source.url
            or old['source_sha256'] != source.pdf_sha256 or len(files) != 1
            or files[0]['sha256'] != source.pdf_sha256 or digest(pdf_path.read_bytes()) != source.pdf_sha256):
        raise ValueError(f'Official {source.state} 2014 source identity differs')
    summaries = read_summary_pages(pdf_path)
    if set(summaries) != set(range(1, source.seats + 1)):
        raise ValueError(f'Official {source.state} 2014 summary coverage differs')
    revised = json.loads(old_body)
    results = []
    with fitz.open(pdf_path) as pdf:
        cover = pdf[0].get_text().upper()
        if source.state.upper() not in cover or '2014' not in cover:
            raise ValueError(f'Official PDF cover does not identify {source.state} 2014')
        for record in revised['records']:
            code = record['code']
            if code not in source.codes:
                continue
            summary = summaries[code]
            detail_valid = sum(candidate['votes'] for candidate in record['candidates']
                               if candidate.get('is_nota') is not True)
            expected_note = (f'{NOTE_PREFIX} Detailed valid votes: {detail_valid:,}; '
                             f'official summary valid votes: {summary["valid_candidate_votes"]:,}.')
            if (record['status'] != 'needs_review'
                    or record.get('source_warning_code') != 'official_summary_turnout_only'
                    or record.get('error') != expected_note
                    or record.get('summary_result') is not None
                    or record.get('summary_page') != summary['summary_page']
                    or record.get('summary_totals') != {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
                    or record.get('summary_source_file') != old['source_file']
                    or record.get('summary_source_sha256') != source.pdf_sha256
                    or record.get('electors') != summary['electors']
                    or record.get('votes_polled') != summary['votes_polled']
                    or record.get('valid_candidate_votes') != detail_valid
                    or not 0 < abs(detail_valid - summary['valid_candidate_votes']) <= 19
                    or record.get('number_of_seats', 1) != 1
                    or record.get('state_name') != source.state
                    or normalized(record['name']) != normalized(summary['name'])
                    or len(record.get('candidates') or []) < 2):
                raise ValueError(f'Prior reviewed {source.state} 2014 constituency differs: {code}')
            text = pdf[summary['summary_page'] - 1].get_text(sort=True)
            identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
            winner = re.search(r'^\s*WINNER\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            runner = re.search(r'^\s*RUNN?ER-UP\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            margin = re.search(r'^\s*MARGIN\s+(\d+)\b', text, re.I | re.M)
            if (not all((identity, winner, runner, margin))
                    or f'legislative assembly of {source.state}'.lower() not in text.lower()
                    or '2014' not in text[:220]
                    or int(identity[1]) != code
                    or normalized(identity[2]) != normalized(record['name'])):
                raise ValueError(f'Official {source.state} 2014 declaration identity differs: {code}')
            first, second = record['candidates'][:2]
            winner_votes, runner_votes, printed_margin = int(winner[3]), int(runner[3]), int(margin[1])
            if (normalized(first['candidate_name']) != normalized(winner[2])
                    or normalized(second['candidate_name']) != normalized(runner[2])
                    or first['party_at_election'] != winner[1]
                    or second['party_at_election'] != runner[1]
                    or first['votes'] != winner_votes or second['votes'] != runner_votes
                    or printed_margin != winner_votes - runner_votes
                    or not 0 < runner_votes < winner_votes <= summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']):
                raise ValueError(f'Official {source.state} 2014 result does not reconcile: {code}')
            result = {'winner': winner[2].strip(), 'winner_party': winner[1].strip(),
                      'winner_votes': winner_votes, 'runner': runner[2].strip(),
                      'runner_party': runner[1].strip(), 'runner_votes': runner_votes,
                      'margin': printed_margin}
            record['summary_result'] = result
            record['error'] += RESULT_NOTE
            results.append({'code': code, 'page': summary['summary_page'],
                            'detail_candidate_sum': detail_valid,
                            'official_summary_valid': summary['valid_candidate_votes'], **result})
    if {item['code'] for item in results} != source.codes or len(results) != len(source.codes):
        raise ValueError(f'{source.state} 2014 declared-result inventory differs')
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if changed != ({'summary_result', 'error'} if before['code'] in source.codes else set()):
            raise ValueError(f'Unrelated {source.state} 2014 evidence changed: {before["code"]}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    detail = {'edition': source.edition, 'year': 2014, 'state': source.state,
              'source_url': source.url, 'source_file': old['source_file'],
              'source_sha256': source.pdf_sha256, 'previous_sha256': digest(old_body),
              'new_sha256': digest(new_body), 'results': results,
              'prior_packages': list(PRIOR_PACKAGES)}
    return old_body, new_body, detail


def build(source: Source, root: Path = ROOT) -> dict:
    name = f'pollmedia-ac-2014-{source.slug}-small-discrepancy-results-20261003'
    output = root / 'exports' / (name + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(source, root)
    old_sha = digest(old_body)
    with tempfile.TemporaryDirectory(prefix=f'ac-2014-{source.slug}-results-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        inner = []
        for kind, relative, body in (
                ('snapshot', f'election-archive/{source.edition}/extraction-{old_sha}.json', old_body),
                ('correction', f'election-archive/{source.edition}/extraction.json', new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{source.edition}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    old_sha if kind == 'correction' else None,
                    f'election-archive/{source.edition}/extraction-{old_sha}.json' if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', source.edition + '\n')
            archive.writestr('AUDIT.json', json.dumps(detail, indent=2))
            archive.writestr('IMPORT.sh', import_script([source.edition]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'results': len(detail['results']),
            'previous_sha256': old_sha, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps([build(source) for source in SOURCES]))
