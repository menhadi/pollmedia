"""Package two single-seat AC results reconciled against their official summaries."""

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


NAME = 'pollmedia-ac-bihar-1995-tn-1991-official-summary-results-20261003'
TARGETS = {
    'ebbd37bd2effa96eacc7acc0': (203, 1995, '5f4981efd65c66679ff0a908720e8560235974ab40594712efa83c9da52473a2'),
    'f0d9e36a60bcef19312cabc4': (151, 1991, '801694fa3fb024f7d9b1eac43b9db19d5713f14b4825ef6f62b0d2c2ca7d67f1'),
}
NOTE = 'Official constituency summary confirms turnout, winner and margin; see the source report.'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path, edition: str, code: int, year: int, prior_sha: str) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / edition
    old_body = (folder / 'extraction.json').read_bytes()
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_file = old['source_file']
    sources = [item for item in manifest['files'] if item['file'] == source_file]
    source_path = folder / source_file
    if (digest(old_body) != prior_sha or old['kind'] != 'ac' or old['year'] != year
            or old['source_url'] != manifest['url'] or len(sources) != 1
            or old['source_sha256'] != sources[0]['sha256'] or not source_path.is_file()
            or source_path.is_symlink() or digest(source_path.read_bytes()) != sources[0]['sha256']):
        raise ValueError('Official edition identity or checksum differs: ' + edition)
    summaries = read_summary_pages(source_path)
    if len(summaries) != len(old['records']) or set(summaries) != {r['code'] for r in old['records']}:
        raise ValueError('Official summary coverage differs: ' + edition)
    revised = json.loads(old_body)
    original = next(r for r in old['records'] if r['code'] == code)
    record = next(r for r in revised['records'] if r['code'] == code)
    if (record['status'] != 'needs_review' or record['number_of_seats'] != 1
            or record['error'] != 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
            or record.get('summary_totals') is not None or record.get('winner') is not None
            or record.get('margin') is not None or record.get('source_warning_code') is not None
            or record.get('source_discrepancy') is not None):
        raise ValueError('Target record differs: ' + edition)
    summary = summaries[code]
    with fitz.open(source_path) as pdf:
        result, discrepancy = verified_summary(record, summary, pdf[summary['summary_page'] - 1].get_text(sort=True))
    if discrepancy is not None or sum(c['votes'] for c in record['candidates']) != summary['valid_candidate_votes']:
        raise ValueError('Detailed and summary candidate totals differ: ' + edition)
    ranked = sorted(record['candidates'], key=lambda c: c['votes'], reverse=True)
    if (len(ranked) < 2 or ranked[0]['votes'] <= ranked[1]['votes']
            or any((ranked[index]['candidate_name'], ranked[index]['party_at_election'], ranked[index]['votes'])
                   != (result[label], result[label + '_party'], result[label + '_votes'])
                   for index, label in enumerate(('winner', 'runner')))):
        raise ValueError('Summary result does not match detail candidate rows: ' + edition)
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_summary_turnout_only'
    record['summary_page'] = summary['summary_page']
    record['summary_totals'] = {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
    record['summary_result'] = result
    record['summary_source_file'] = source_file
    record['summary_source_sha256'] = sources[0]['sha256']
    expected = {'original_extraction_warning', 'error', 'source_warning_code', 'summary_page',
                'summary_totals', 'summary_result', 'summary_source_file', 'summary_source_sha256'}
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (expected if before['code'] == code else set()):
            raise ValueError('Unrelated source value changed: ' + edition)
    if original['candidates'] != record['candidates']:
        raise ValueError('Candidate evidence changed: ' + edition)
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': edition, 'code': code, 'year': year,
                                'source_url': old['source_url'], 'source_file': source_file,
                                'source_sha256': sources[0]['sha256'], 'previous_sha256': prior_sha,
                                'new_sha256': digest(new_body), 'summary_page': summary['summary_page'],
                                'winner': result['winner'], 'margin': result['margin']}


def build(root: Path) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    details = []
    with tempfile.TemporaryDirectory(prefix='ac-two-summaries-', dir=root / 'exports') as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        inner = []
        for edition, (code, year, prior_sha) in TARGETS.items():
            old_body, new_body, detail = revised_edition(root, edition, code, year, prior_sha)
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
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Two official AC summary results', 'editions': details}, indent=2))
            archive.writestr('IMPORT.sh', import_script(list(TARGETS)))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': digest(output.read_bytes()), 'records': len(details)}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
