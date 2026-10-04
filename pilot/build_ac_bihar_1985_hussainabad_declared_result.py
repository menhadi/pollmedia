"""Add the official Hussainabad declaration to the prior Bihar 1985 correction."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '60b51eb873eefae10d9f9aae'
NAME = 'pollmedia-ac-bihar-1985-hussainabad-declared-result-20261004'
PRIOR_BUNDLE = 'pollmedia-ac-bihar-1985-summary-reconciliation-20261003.zip'
OLD_SHA = '432a5e6f121da1cc5519621a140beb1aefc69198b9fd55fefd05ed46a214ba9c'
SOURCE_FILE = f'{EDITION}-9213.pdf'
SOURCE_SHA = '4197eac7c44fc05b3065635e6eac630289a2ce1812665ba24fd341b0522c1828'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3896-bihar-1985/'
OLD_NOTE = 'Official constituency summary supplies electors and voters; detailed candidate rows remain under review.'
NOTE = ('Official summary confirms turnout, winner and margin. The detailed page uses an unsupported layout; '
        'candidate rows remain preserved for review.')


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    prior_bundle = root / 'exports' / PRIOR_BUNDLE
    expected_bundle_sha = prior_bundle.with_suffix('.sha256').read_text(encoding='ascii').split()[0]
    if sha(prior_bundle.read_bytes()) != expected_bundle_sha:
        raise ValueError('Prior Bihar 1985 correction checksum differs')
    with zipfile.ZipFile(prior_bundle) as outer:
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            old_body = inner.read(f'election-archive/{EDITION}/extraction.json')
            prior_manifest = json.loads(inner.read('manifest.json'))['files'][0]
    if sha(old_body) != OLD_SHA or prior_manifest['sha256'] != OLD_SHA:
        raise ValueError('Prior Bihar 1985 extraction bytes differ')
    data = json.loads(old_body)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_path = folder / SOURCE_FILE
    if (data['kind'] != 'ac' or data['year'] != 1985 or len(data['records']) != 324
            or data['source_url'] != SOURCE_URL or data['source_file'] != SOURCE_FILE
            or data['source_sha256'] != SOURCE_SHA or manifest['url'] != SOURCE_URL
            or len([item for item in manifest['files'] if item['file'] == SOURCE_FILE
                    and item['sha256'] == SOURCE_SHA]) != 1 or sha(source_path.read_bytes()) != SOURCE_SHA):
        raise ValueError('Official Bihar 1985 source identity differs')
    revised = json.loads(old_body)
    record = next(row for row in revised['records'] if row['code'] == 324)
    with fitz.open(source_path) as pdf:
        text = pdf[344].get_text(sort=True)
        detail = pdf[446].get_text(sort=True)
    winner = re.search(r'^\s*Winner\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    runner = re.search(r'^\s*Runner up\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    margin = re.search(r'^\s*MARGIN\s*:\s*(\d+)\b', text, re.I | re.M)
    ranked = sorted(record['candidates'], key=lambda candidate: -candidate['votes'])
    if (record['name'] != 'HUSSAINABAD' or record['state_name'] != 'Bihar'
            or record['status'] != 'needs_review' or record['error'] != OLD_NOTE
            or record['number_of_seats'] != 1 or record['detail_page'] != 447
            or record['summary_page'] != 345 or record['summary_source_file'] != SOURCE_FILE
            or record['summary_source_sha256'] != SOURCE_SHA
            or record['source_warning_code'] != 'official_summary_turnout_only'
            or record.get('summary_result') is not None or len(record['candidates']) != 19
            or (record['electors'], record['votes_polled'], record.get('valid_candidate_votes')) != (117664, 57760, None)
            or record['summary_totals'] != {'electors': 117664, 'votes_polled': 57760,
                                            'valid_candidate_votes': 56119}
            or sum(candidate['votes'] for candidate in record['candidates']) != 56119
            or re.search(r'Field7:CONSTITUENCY\s*:\s*324\s*-\s*HUSSAINABAD\b', text, re.I) is None
            or 'HUSSAINABAD' not in detail.upper() or not all((winner, runner, margin))
            or (winner[2].strip(), winner[1], int(winner[3])) != ('HARIHAR SINGH', 'INC', 11254)
            or (runner[2].strip(), runner[1], int(runner[3])) != ('LACHHUMAN RAM', 'LKD', 8928)
            or int(margin[1]) != 2326
            or [(ranked[i]['candidate_name'], ranked[i]['party_at_election'], ranked[i]['votes']) for i in range(2)] != [
                ('HARIHAR SINGH', 'INC', 11254), ('LACHHUMAN RAM', 'LKD', 8928)]):
        raise ValueError('Hussainabad 1985 source evidence differs')
    record['previous_review_note'] = record['error']
    record['error'] = NOTE
    record['summary_result'] = {'winner': 'HARIHAR SINGH', 'winner_party': 'INC', 'winner_votes': 11254,
                                'runner': 'LACHHUMAN RAM', 'runner_party': 'LKD', 'runner_votes': 8928,
                                'margin': 2326}
    changed_fields = {'previous_review_note', 'error', 'summary_result'}
    for before, after in zip(data['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (changed_fields if before['code'] == 324 else set()):
            raise ValueError('Unrelated Bihar 1985 evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 1985, 'code': 324,
                                'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                                'source_sha256': SOURCE_SHA, 'summary_page': 345,
                                'candidate_count': 19, 'previous_sha256': sha(old_body),
                                'new_sha256': sha(new_body), 'winner': 'HARIHAR SINGH', 'margin': 2326}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / f'{NAME}.zip'
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-1985-hussainabad-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{audit["previous_sha256"]}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    audit['previous_sha256'] if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{sha(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Hussainabad 1985 declared AC result after Bihar summary reconciliation', **audit}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    bundle_sha = sha(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{bundle_sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': bundle_sha, **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
