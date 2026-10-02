"""Restore Malda's source-verified 1991 summary totals after its margin-label parse gap."""

import hashlib
import io
import json
from pathlib import Path
import tempfile
import zipfile

import fitz

from build_pc_1991_summary_result_bundle import EDITION, SUMMARY_FILE
from build_pc_1992_summary_result_bundle import verified_summary
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


NAME = 'pollmedia-pc-1991-malda-summary-totals-20261002'
PRIOR_NAME = 'pollmedia-pc-1991-summary-results-20261002'
MALDA_CODE = 473


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def build(root: Path) -> dict:
    exports = root / 'exports'
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_sha = next(file['sha256'] for file in manifest['files'] if file['file'] == SUMMARY_FILE)
    source_path = folder / SUMMARY_FILE
    if source_path.is_symlink() or digest(source_path.read_bytes()) != source_sha:
        raise ValueError('Official summary PDF checksum differs')

    with zipfile.ZipFile(exports / (PRIOR_NAME + '.zip')) as prior:
        prior_audit = json.loads(prior.read('AUDIT.json'))
        with zipfile.ZipFile(io.BytesIO(prior.read('correction-' + EDITION + '.zip'))) as correction:
            old_body = correction.read(f'election-archive/{EDITION}/extraction.json')
    old_sha = digest(old_body)
    if prior_audit['new_sha256'] != old_sha or prior_audit['edition'] != EDITION:
        raise ValueError('Previous live revision does not match the first correction')
    old = json.loads(old_body)
    if old['kind'] != 'pc' or old['year'] != 1991 or old['source_url'] != manifest['url']:
        raise ValueError('1991 election identity differs')

    new = json.loads(old_body)
    matching = [record for record in new['records'] if record['code'] == MALDA_CODE]
    if len(matching) != 1:
        raise ValueError('Malda record is missing or duplicated')
    record = matching[0]
    if (record['state_name'] != 'WEST BENGAL' or record['constituency_name'] != 'MALDA'
            or record['status'] != 'needs_review' or 'summary_totals' in record
            or record['source_warning_code'] != 'official_pc_summary_reconciled_serial_gap'
            or 'Summary label missing or ambiguous: MARGIN' not in record['original_extraction_warning']
            or record['summary_source_file'] != SUMMARY_FILE
            or record['summary_source_sha256'] != source_sha):
        raise ValueError('Existing Malda review evidence differs')
    with fitz.open(source_path) as pdf:
        if not 1 <= record['summary_page'] <= len(pdf):
            raise ValueError('Malda summary page is out of range')
        result = verified_summary(pdf[record['summary_page'] - 1].get_text(sort=True), record)
    if result != record['summary_result'] or result['margin'] != 1820:
        raise ValueError('Official Malda result differs from the imported correction')
    record['summary_totals'] = {field: record[field]
                                for field in ('electors', 'votes_polled', 'valid_candidate_votes')}
    for before, after in zip(old['records'], new['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != ({'summary_totals'} if before['code'] == MALDA_CODE else set()):
            raise ValueError('An unrelated election value changed')
    new_body = json.dumps(new, ensure_ascii=False, indent=2).encode('utf-8')
    new_sha = digest(new_body)

    with tempfile.TemporaryDirectory(prefix='pc-1991-malda-', dir=exports) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{old_sha}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        for relative, body in ((snapshot, old_body), (revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
        inner = []
        for kind, relative in (('snapshot', snapshot), ('correction', revision)):
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    old_sha if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({
                'scope': '1991 Malda PC official summary totals; candidate votes and warnings unchanged',
                'edition': EDITION, 'source_url': old['source_url'], 'summary_file': SUMMARY_FILE,
                'summary_sha256': source_sha, 'summary_page': record['summary_page'],
                'previous_sha256': old_sha, 'new_sha256': new_sha,
                'code': MALDA_CODE, 'result': result,
            }, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_bytes(
        f'{digest(output.read_bytes())}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'previous_sha256': old_sha, 'new_sha256': new_sha,
            'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
