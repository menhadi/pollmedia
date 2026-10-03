"""Package four source-reconciled 1996/1998 PC results after detail revision."""

import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

from audit_pc_ac_zero_values import correction_index, effective_body
from audit_pc_1996_1998_name_mismatches import EDITIONS, ROOT, WARNING, audit
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


NAME = 'pollmedia-pc-1996-1998-name-variant-results-20261003'
PRIOR_PACKAGE = 'pollmedia-pc-1996-1999-detailed-results-20261003.zip'
TRUNCATION_NOTE = ('Official summary prints “Mumbai South Centra”; the detailed report prints '
                   '“MUMBAI SOUTH CENTRAL”. Turnout, candidates and margin reconcile; review the official PDFs.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    revisions = correction_index(root, (PRIOR_PACKAGE,))
    evidence = audit(root)
    if len(evidence) != 4:
        raise ValueError('1996/1998 PC name-warning coverage differs')
    details = []
    with tempfile.TemporaryDirectory(prefix='pc-old-names-', dir=output.parent) as temporary:
        temporary = Path(temporary)
        staged = temporary / 'archive'
        packages = temporary / 'packages'
        packages.mkdir()
        for year, (edition, detail_file, summary_file) in EDITIONS.items():
            if len(revisions.get(edition, [])) != 1:
                raise ValueError(f'{year}: prior detail correction is missing')
            folder = root / 'application/storage/app/private/election-archive' / edition
            old_body = effective_body(folder / 'extraction.json', revisions[edition])
            old = json.loads(old_body)
            manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
            files = {item['file']: item for item in manifest['files']}
            if old['kind'] != 'pc' or old['year'] != year or old['source_url'] != manifest['url']:
                raise ValueError(f'{year}: official edition identity differs')
            targets = {row['code']: row for row in evidence if row['year'] == year}
            if set(targets) != {47, 253}:
                raise ValueError(f'{year}: verified seat identities differ')
            revised = json.loads(old_body)
            for record in revised['records']:
                source = targets.get(record['code'])
                if source is None:
                    continue
                if record['error'] != WARNING or record['status'] != 'needs_review' \
                        or record['number_of_seats'] != 1 or record.get('winner') is not None \
                        or record.get('margin') is not None or record.get('source_warning_code') is not None:
                    raise ValueError(f'{year} seat {record["code"]}: prior state differs')
                record['original_extraction_warning'] = record['error']
                if record['code'] == 253:
                    record['error'] = TRUNCATION_NOTE
                record['source_warning_code'] = 'official_pc_summary_reconciled_detail_warning'
                record['detail_candidate_count'] = len(record['candidates'])
                record['summary_result'] = source['result']
                record['summary_source_file'] = summary_file
                record['summary_source_sha256'] = files[summary_file]['sha256']
                record['detail_source_file'] = detail_file
                record['detail_source_sha256'] = files[detail_file]['sha256']
                record['official_summary_constituency_name'] = source['summary']
            allowed = {'original_extraction_warning', 'source_warning_code', 'detail_candidate_count',
                       'summary_result', 'summary_source_file', 'summary_source_sha256',
                       'detail_source_file', 'detail_source_sha256', 'official_summary_constituency_name'}
            for before, after in zip(old['records'], revised['records']):
                changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
                expected = allowed | ({'error'} if before['code'] == 253 else set()) if before['code'] in targets else set()
                if before['code'] != after['code'] or changed != expected:
                    raise ValueError(f'{year}: unrelated seat value changed')
            new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
            old_sha = digest(old_body)
            new_sha = digest(new_body)
            snapshot = f'election-archive/{edition}/extraction-{old_sha}.json'
            revision = f'election-archive/{edition}/extraction.json'
            for relative, body in ((snapshot, old_body), (revision, new_body)):
                path = staged / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(body)
            details.append({'edition': edition, 'year': year, 'source_url': old['source_url'],
                            'detail_file': detail_file, 'detail_sha256': files[detail_file]['sha256'],
                            'summary_file': summary_file, 'summary_sha256': files[summary_file]['sha256'],
                            'previous_sha256': old_sha, 'new_sha256': new_sha,
                            'codes': sorted(targets)})
        inner = []
        for kind in ('snapshot', 'correction'):
            for detail in details:
                edition = detail['edition']
                previous = f'election-archive/{edition}/extraction-{detail["previous_sha256"]}.json'
                relative = previous if kind == 'snapshot' else f'election-archive/{edition}/extraction.json'
                path = packages / f'{kind}-{edition}.zip'
                bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
                package(staged, path, 'election-archive', bucket, 8, [relative],
                        detail['previous_sha256'] if kind == 'correction' else None,
                        previous if kind == 'correction' else None)
                inner.append(path)
        partial = output.with_suffix('.zip.partial')
        if partial.exists():
            raise FileExistsError(partial)
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for path in inner:
                zipped.write(path, path.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            zipped.writestr('ARCHIVES', ''.join(detail['edition'] + '\n' for detail in details))
            zipped.writestr('AUDIT.json', json.dumps({'scope': 'Four 1996/1998 PC summary/detail name variants; original votes unchanged',
                                                    'prior_package': PRIOR_PACKAGE, 'editions': details}, indent=2))
            zipped.writestr('IMPORT.sh', import_script([detail['edition'] for detail in details]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes((sha + '  ' + output.name + '\n').encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'records': 4,
            'editions': len(details)}


if __name__ == '__main__':
    print(json.dumps(build()))
