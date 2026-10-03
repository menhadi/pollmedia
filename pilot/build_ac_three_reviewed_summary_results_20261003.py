"""Retain incomplete AC detail rows while showing three printed summary results."""

import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

import fitz

from build_ac_1996_assam_summary_discrepancy_bundle import verified_summary
from build_pc_ac_zero_turnout_bundle import import_script
from extract_assembly_summary_totals import read_summary_pages
from preserve_archive_json import package


NAME = 'pollmedia-ac-three-reviewed-summary-results-20261003'
# edition: (constituency code, election year, archived extraction SHA, candidate sum minus summary valid votes)
TARGETS = {
    '650699d0f10c4acd449cd5a9': (199, 1983, '271613ebf07bb02eb90f8e93b53f7df0cc5898a5ea31348af2c89257beb87bc6', -2000),
    'b089f1ea6668b55e759ee45b': (169, 1995, 'a620a36d7d5f4140d449b69dc7de9a945d2073996dae33533f7bf89118c754a2', -91),
    '1cc8415ab4d57b66831417e8': (400, 1996, 'a58694dfd7d6183226f4c9bd084d842315974e0afceaef62a2badf52ca52f286', 36),
}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path, edition: str, code: int, year: int, prior_sha: str, expected_delta: int) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / edition
    old_body = (folder / 'extraction.json').read_bytes()
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_file = old['source_file']
    files = [item for item in manifest['files'] if item['file'] == source_file]
    source_path = folder / source_file
    if (digest(old_body) != prior_sha or old['kind'] != 'ac' or old['year'] != year
            or old['source_url'] != manifest['url'] or len(files) != 1
            or old['source_sha256'] != files[0]['sha256'] or not source_path.is_file()
            or source_path.is_symlink() or digest(source_path.read_bytes()) != files[0]['sha256']):
        raise ValueError('Official source identity or checksum differs: ' + edition)
    summaries = read_summary_pages(source_path)
    if len(summaries) != len(old['records']) or set(summaries) != {record['code'] for record in old['records']}:
        raise ValueError('Official summary coverage differs: ' + edition)
    revised = json.loads(old_body)
    record = next(record for record in revised['records'] if record['code'] == code)
    summary = summaries[code]
    if (record['status'] != 'needs_review' or record['number_of_seats'] != 1
            or record.get('summary_totals') is not None or record.get('summary_result') is not None
            or record.get('winner') is not None or record.get('margin') is not None
            or record.get('source_warning_code') is not None or record.get('source_discrepancy') is not None
            or record.get('summary_page') not in (None, summary['summary_page']) or not record.get('error')):
        raise ValueError('Target record differs: ' + edition)
    with fitz.open(source_path) as pdf:
        result, polled_discrepancy = verified_summary(record, summary, pdf[summary['summary_page'] - 1].get_text(sort=True))
    candidates = record['candidates']
    candidate_sum = sum(candidate['votes'] for candidate in candidates)
    keys = {(candidate['candidate_name'].casefold(), candidate['party_at_election'].casefold(), candidate['votes'])
            for candidate in candidates}
    ranked = sorted(candidates, key=lambda candidate: candidate['votes'], reverse=True)
    if (len(keys) != len(candidates) or len(ranked) < 2 or ranked[0]['votes'] <= ranked[1]['votes']
            or candidate_sum - summary['valid_candidate_votes'] != expected_delta
            or any((ranked[index]['candidate_name'], ranked[index]['party_at_election'], ranked[index]['votes'])
                   != (result[label], result[label + '_party'], result[label + '_votes'])
                   for index, label in enumerate(('winner', 'runner')))):
        raise ValueError('Detailed candidate evidence differs from reviewed result: ' + edition)
    record['original_extraction_warning'] = record['error']
    record['error'] = (f'Official summary confirms turnout, winner and margin. Detailed candidate votes total '
                       f'{candidate_sum:,}; summary valid votes are {summary["valid_candidate_votes"]:,}. '
                       'Detailed candidate rows remain under review.')
    record['source_warning_code'] = 'official_summary_turnout_only'
    record['summary_page'] = summary['summary_page']
    record['summary_totals'] = {field: summary[field] for field in ('electors', 'votes_polled', 'valid_candidate_votes')}
    record['summary_result'] = result
    record['summary_source_file'] = source_file
    record['summary_source_sha256'] = files[0]['sha256']
    expected_fields = {'original_extraction_warning', 'error', 'source_warning_code', 'summary_page',
                       'summary_totals', 'summary_result', 'summary_source_file', 'summary_source_sha256'}
    if next(item for item in old['records'] if item['code'] == code).get('summary_page') == summary['summary_page']:
        # The source may already have a locator; it is retained unchanged.
        expected_fields.discard('summary_page')
    if polled_discrepancy:
        record['source_discrepancy'] = polled_discrepancy
        expected_fields.add('source_discrepancy')
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (expected_fields if before['code'] == code else set()):
            raise ValueError(f'Unrelated constituency evidence changed: {edition} {before["code"]} '
                             f'{sorted(changed)} expected {sorted(expected_fields if before["code"] == code else set())}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': edition, 'code': code, 'year': year,
                                'source_url': old['source_url'], 'source_file': source_file,
                                'source_sha256': files[0]['sha256'], 'previous_sha256': prior_sha,
                                'new_sha256': digest(new_body), 'summary_page': summary['summary_page'],
                                'winner': result['winner'], 'margin': result['margin'],
                                'candidate_sum_difference': expected_delta,
                                'turnout_discrepancy': polled_discrepancy is not None}


def build(root: Path) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    details = []
    with tempfile.TemporaryDirectory(prefix='ac-reviewed-summary-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        inner = []
        for edition, (code, year, prior_sha, difference) in TARGETS.items():
            old_body, new_body, detail = revised_edition(root, edition, code, year, prior_sha, difference)
            details.append(detail)
            snapshot = f'election-archive/{edition}/extraction-{prior_sha}.json'
            revision = f'election-archive/{edition}/extraction.json'
            for relative, body in ((snapshot, old_body), (revision, new_body)):
                target = staged / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(body)
            for kind, relative in (('snapshot', snapshot), ('correction', revision)):
                bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
                path = packages / f'{kind}-{edition}.zip'
                package(staged, path, 'election-archive', bucket, 8, [relative],
                        prior_sha if kind == 'correction' else None,
                        snapshot if kind == 'correction' else None)
                inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', ''.join(edition + '\n' for edition in TARGETS))
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Three reviewed official AC summary results',
                                                      'editions': details}, indent=2))
            archive.writestr('IMPORT.sh', import_script(list(TARGETS)))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': digest(output.read_bytes()), 'records': len(details)}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
