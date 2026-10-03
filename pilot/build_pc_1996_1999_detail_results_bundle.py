"""Package verified one-seat PC detail results lacking an independent summary."""

import copy
import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

from audit_pc_1996_1999_detail_gaps import EDITIONS, WARNING, source_check
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
NAME = 'pollmedia-pc-1996-1999-detailed-results-20261003'
NOTE = ('Official detailed result prints turnout and candidate votes; no independent '
        'constituency summary was available. Review the official PDF.')
METHOD = 'official detailed-result PDF; top candidate rows and totals checked'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    details = []
    with tempfile.TemporaryDirectory(prefix='pc-detail-', dir=output.parent) as temporary:
        temporary = Path(temporary)
        staged = temporary / 'archive'
        packages = temporary / 'packages'
        packages.mkdir()
        for year, edition in EDITIONS.items():
            folder = root / 'application/storage/app/private/election-archive' / edition
            old_body = (folder / 'extraction.json').read_bytes()
            data = json.loads(old_body)
            verified = source_check(year, edition)
            by_code = {item['code']: item for item in verified}
            if len(by_code) != 15 or len(data['records']) != 543:
                raise ValueError(f'{year}: source coverage differs')
            revised = copy.deepcopy(data)
            for record in revised['records']:
                source = by_code.get(record['code'])
                if source is None:
                    continue
                if record.get('error') != WARNING or record.get('status') != 'needs_review' \
                        or record.get('number_of_seats') != 1 or record.get('detail_page') != source['heading_page']:
                    raise ValueError(f'{year} {record["code"]}: review or seat identity differs')
                record['original_extraction_warning'] = record['error']
                record['error'] = NOTE
                record['source_warning_code'] = 'official_pc_detailed_result_verified'
                record['detail_source_file'] = data['source_file']
                record['detail_source_sha256'] = data['source_sha256']
                record['detail_verified_totals'] = {
                    'electors': source['electors'],
                    'votes_polled': source['votes_polled'],
                    'valid_candidate_votes': source['valid_candidate_votes'],
                    'source_page': source['source_page'],
                    'method': METHOD,
                }
                record['detail_verified_result'] = source['result']
            for before, after in zip(data['records'], revised['records']):
                unchanged = ['code', 'official_pc_code', 'name', 'state_name', 'constituency_name',
                             'number_of_seats', 'electors', 'votes_polled', 'valid_candidate_votes',
                             'candidates', 'detail_page', 'status']
                if any(before.get(key) != after.get(key) for key in unchanged):
                    raise ValueError(f'{year}: original result bytes or identity changed')
                if before['code'] not in by_code and before != after:
                    raise ValueError(f'{year}: unrelated seat changed')
            new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
            old_sha = digest(old_body)
            snapshot = f'election-archive/{edition}/extraction-{old_sha}.json'
            revision = f'election-archive/{edition}/extraction.json'
            for relative, body in ((snapshot, old_body), (revision, new_body)):
                path = staged / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(body)
            details.append({'edition': edition, 'year': year, 'source_url': data['source_url'],
                            'source_file': data['source_file'], 'source_sha256': data['source_sha256'],
                            'previous_sha256': old_sha, 'new_sha256': digest(new_body),
                            'codes': sorted(by_code)})
        inner = []
        for kind in ('snapshot', 'correction'):
            for detail in details:
                edition = detail['edition']
                previous = f'election-archive/{edition}/extraction-{detail["previous_sha256"]}.json'
                relative = previous if kind == 'snapshot' else f'election-archive/{edition}/extraction.json'
                file = packages / f'{kind}-{edition}.zip'
                bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
                package(staged, file, 'election-archive', bucket, 8, [relative],
                        detail['previous_sha256'] if kind == 'correction' else None,
                        previous if kind == 'correction' else None)
                inner.append(file)
        partial = output.with_suffix('.zip.partial')
        if partial.exists():
            raise FileExistsError(partial)
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for file in inner:
                zipped.write(file, file.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{digest(file.read_bytes())}  {file.name}\n' for file in inner))
            zipped.writestr('ARCHIVES', ''.join(item['edition'] + '\n' for item in details))
            zipped.writestr('AUDIT.json', json.dumps({'scope': '45 one-seat PC detail results; original votes and candidate rows unchanged',
                                                    'editions': details}, indent=2))
            zipped.writestr('IMPORT.sh', import_script([item['edition'] for item in details]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_text(sha + '  ' + output.name + '\n', encoding='ascii')
    return {'bundle': str(output), 'sha256': sha, 'editions': len(details), 'records': 45}


if __name__ == '__main__':
    print(json.dumps(build()))
