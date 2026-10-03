"""Verify the remaining four 1996/1998 PC name-warning results."""

import hashlib
import json
from pathlib import Path

import fitz

from audit_pc_1999_name_mismatches import simple, verified_result, without_reservation
from extract_pc_legacy import summary_identity


ROOT = Path(__file__).resolve().parents[1]
EDITIONS = {
    1996: ('35f16085183f0c8bd7ef6124', '35f16085183f0c8bd7ef6124-9770.pdf',
           '35f16085183f0c8bd7ef6124-9771.pdf'),
    1998: ('f3bccf66ec1c16f1f9c1ffba', 'f3bccf66ec1c16f1f9c1ffba-9773.pdf',
           'f3bccf66ec1c16f1f9c1ffba-9774.pdf'),
}
WARNING = 'Detailed and summary constituency names differ'


def audit(root: Path = ROOT) -> list[dict]:
    found = []
    for year, (edition, detail_file, summary_file) in EDITIONS.items():
        folder = root / 'application/storage/app/private/election-archive' / edition
        data = json.loads((folder / 'extraction.json').read_text(encoding='utf-8'))
        manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
        if data['kind'] != 'pc' or data['year'] != year or manifest['url'] != data['source_url']:
            raise ValueError(f'{year}: edition identity differs')
        files = {item['file']: item for item in manifest['files']}
        for filename in (detail_file, summary_file):
            if hashlib.sha256((folder / filename).read_bytes()).hexdigest() != files[filename]['sha256']:
                raise ValueError(f'{year}: official PDF checksum differs')
        targets = [record for record in data['records'] if record.get('error') == WARNING]
        if len(data['records']) != 543 or {record['code'] for record in targets} != {47, 253}:
            raise ValueError(f'{year}: name-warning coverage differs')
        with fitz.open(folder / detail_file) as detail, fitz.open(folder / summary_file) as summary:
            for record in targets:
                if record['status'] != 'needs_review' or record['number_of_seats'] != 1:
                    raise ValueError(f'{year} {record["code"]}: reviewed one-seat identity differs')
                raw_summary = summary[record['summary_page'] - 1].get_text()
                state, state_code, code, name = summary_identity(raw_summary)
                if simple(state) != simple(record['state_name']) or state_code != record['state_code'] \
                        or code != record['official_pc_code']:
                    raise ValueError(f'{year} {record["code"]}: printed jurisdiction differs')
                detail_name = record['constituency_name']
                allowed = (record['code'] == 47 and simple(without_reservation(detail_name)) == simple(name)) \
                    or (record['code'] == 253 and detail_name == 'MUMBAI SOUTH CENTRAL'
                        and name == 'Mumbai South Centra')
                if not allowed:
                    raise ValueError(f'{year} {record["code"]}: name difference is not the documented variant')
                result = verified_result(record, summary[record['summary_page'] - 1].get_text(sort=True),
                                         detail[record['detail_page'] - 1].get_text())
                found.append({'year': year, 'edition': edition, 'code': record['code'],
                              'state': state, 'detail': detail_name, 'summary': name,
                              'result': result})
    return found


if __name__ == '__main__':
    for row in audit():
        print(row['year'], row['code'], row['detail'], '|', row['summary'], '|', row['result']['margin'])
