"""Recover 28 Uttar Pradesh 1951 AC declarations truncated in summary headings."""

import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

import fitz

from audit_pc_ac_zero_values import correction_index, effective_body
from build_ac_bombay_1951_four_single_seat_results import norm, summary_result
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '402db61ff727c908b4ac3170'
NAME = 'pollmedia-ac-up-1951-28-truncated-summary-results-20261004'
PRIOR_SHA256 = '276a6d92c49e192b90121dbb54e615dd17d47410c2a3ac60fecfe5277667baf2'
SOURCE_SHA256 = '3c2014c43fcc0c5c4636c84fd43cf6d7a68d967736e3f60d44924a7f641bfdb7'
SOURCE_FILE = '402db61ff727c908b4ac3170-7462.pdf'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3241-uttar-pradesh-1951/'
PRIOR_PACKAGES = tuple(f'pollmedia-ac-residual-turnout-corrections-20261001-v{i}.zip' for i in range(1, 5))
CODES = (1, 2, 18, 33, 42, 45, 47, 55, 88, 114, 118, 123, 126, 128, 129, 138,
         162, 163, 174, 182, 214, 218, 238, 244, 257, 284, 319, 336)


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    revisions = correction_index(root, PRIOR_PACKAGES)
    if len(revisions.get(EDITION, [])) != 4:
        raise ValueError('Uttar Pradesh 1951 prior revision chain differs')
    before_body = effective_body(folder / 'extraction.json', revisions[EDITION])
    before = json.loads(before_body)
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    source = folder / SOURCE_FILE
    if (sha(before_body) != PRIOR_SHA256 or before['year'] != 1951 or before['kind'] != 'ac'
            or len(before['records']) != 347 or before['source_url'] != SOURCE_URL
            or before['source_file'] != SOURCE_FILE or before['source_sha256'] != SOURCE_SHA256
            or manifest['url'] != SOURCE_URL
            or len([row for row in manifest['files'] if row['file'] == SOURCE_FILE
                    and row['sha256'] == SOURCE_SHA256]) != 1 or sha(source.read_bytes()) != SOURCE_SHA256):
        raise ValueError('Uttar Pradesh 1951 source edition differs')
    after = json.loads(before_body)
    by_code = {record['code']: record for record in after['records']}
    if len(by_code) != len(after['records']):
        raise ValueError('Uttar Pradesh 1951 duplicate constituency codes')
    with fitz.open(source) as pdf:
        for code in CODES:
            record = by_code[code]
            page = code + 20
            name, totals, result = summary_result(pdf[page - 1].get_text(sort=True), code)
            ranked = sorted(record['candidates'], key=lambda row: -row['votes'])
            if (len(norm(name)) < 15 or not norm(record['name']).startswith(norm(name))
                    or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
                    or record['error'] != 'Summary and detailed identities differ'
                    or record['summary_page'] != page or record.get('summary_result') is not None
                    or record['detail_page'] <= page
                    or totals != {key: record[key] for key in totals}
                    or sum(row['votes'] for row in record['candidates']) != totals['valid_candidate_votes']
                    or len(ranked) < 2
                    or any((result[key], result[key + '_party'], result[key + '_votes'])
                           != (ranked[index]['candidate_name'], ranked[index]['party_at_election'], ranked[index]['votes'])
                           for index, key in enumerate(('winner', 'runner')))):
                raise ValueError(f'Uttar Pradesh 1951 summary identity differs: {code}')
            record['original_extraction_warning'] = record['error']
            record['error'] = ('Official summary heading truncates this constituency name. Its turnout, '
                               'winner and margin agree with the detailed candidate rows; see the report.')
            record['source_warning_code'] = 'summary_only_turnout'
            record['summary_source_file'] = SOURCE_FILE
            record['summary_source_sha256'] = SOURCE_SHA256
            record['summary_totals'] = totals
            record['summary_result'] = result
    changed_fields = {'original_extraction_warning', 'error', 'source_warning_code',
                      'summary_source_file', 'summary_source_sha256', 'summary_totals', 'summary_result'}
    for old, new in zip(before['records'], after['records']):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (changed_fields if old['code'] in CODES else set()):
            raise ValueError(f'Uttar Pradesh 1951 unrelated record changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'edition': EDITION, 'codes': CODES, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
             'source_sha256': SOURCE_SHA256, 'previous_sha256': sha(before_body), 'new_sha256': sha(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    before, after, audit = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-up-1951-', dir=output.parent) as temporary:
        staging = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{PRIOR_SHA256}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, before), ('correction', revision, after)):
            target = staging / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staging, path, 'election-archive', bucket, 8, [relative],
                    PRIOR_SHA256 if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for path in inner:
                zipped.write(path, path.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{sha(path.read_bytes())}  {path.name}\n' for path in inner))
            zipped.writestr('ARCHIVES', EDITION + '\n')
            zipped.writestr('AUDIT.json', json.dumps(audit, indent=2))
            zipped.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    with output.with_suffix('.sha256').open('w', encoding='ascii', newline='\n') as checksum:
        checksum.write(f'{sha(output.read_bytes())}  {output.name}\n')
    return {'bundle': str(output), 'sha256': sha(output.read_bytes()), **audit}


if __name__ == '__main__':
    print(json.dumps(build()))
