"""Read-only inventory of saved PC/AC editions and constituency extraction gaps."""
import argparse
from collections import Counter, defaultdict
import hashlib
import json
from pathlib import Path


def catalogue(root):
    fixtures = root / 'application/database/fixtures'
    general = json.loads((fixtures / 'eci-election-archive.json').read_text(encoding='utf-8'))
    national = json.loads((fixtures / 'eci-assembly-national.json').read_text(encoding='utf-8'))
    entries = [dict(kind='pc', state=None, label=label, year=int(label[:4]), url=url)
               for label, url in general['pc']]
    entries += [dict(kind='ac', state='Uttar Pradesh', label=label, year=int(label[:4]), url=url)
                for label, url in general['ac']]
    entries += [dict(kind='ac', state=item['state'], label=item['label'], year=int(item['year']), url=item['url'])
                for item in national['entries']]
    # The Uttar Pradesh archive is listed in both the legacy AC list and the
    # national AC list. It is one preserved edition, keyed by its official URL.
    return list({entry['url']: entry for entry in entries}.values())


def summarize(root):
    archive = root / 'application/storage/app/private/election-archive'
    result = {'catalogue_editions': 0, 'extracted_editions': 0, 'missing_extractions': [],
              'constituency_records': 0, 'records_without_candidates': [],
              'duplicate_codes_within_edition': [], 'same_name_different_codes': [],
              'extraction_source_mismatches': [], 'internal_ac_code_gaps': [],
              'kinds': {}, 'year_state_editions': []}
    grouped = defaultdict(list)
    for item in catalogue(root):
        result['catalogue_editions'] += 1
        edition = hashlib.sha256(item['url'].encode()).hexdigest()[:24]
        path = archive / edition / 'extraction.json'
        identity = {**item, 'edition_id': edition}
        if not path.exists():
            result['missing_extractions'].append(identity)
            continue
        data = json.loads(path.read_text(encoding='utf-8'))
        if data.get('source_url') != item['url'] or data.get('kind') != item['kind'] or data.get('year') != item['year']:
            result['extraction_source_mismatches'].append(identity)
            continue
        result['extracted_editions'] += 1
        records = data.get('records', [])
        result['constituency_records'] += len(records)
        kind = result['kinds'].setdefault(item['kind'], {'editions': 0, 'records': 0, 'without_candidates': 0})
        kind['editions'] += 1
        kind['records'] += len(records)
        codes = Counter(record.get('code') for record in records)
        names = Counter((str(record.get('state_name') or record.get('state_code') or item['state'] or '').casefold(),
                         str(record.get('constituency_name') or record.get('name') or '').casefold())
                        for record in records)
        for code, count in codes.items():
            if count > 1:
                result['duplicate_codes_within_edition'].append({**identity, 'code': code, 'count': count})
        # A number gap is a review lead, not proof of an omitted contest:
        # some official editions explicitly exclude seats or show uncontested polls.
        ordinary_codes = {code for code in codes if isinstance(code, int) and 1 <= code <= 1000}
        if item['kind'] == 'ac' and ordinary_codes:
            missing = sorted(set(range(1, max(ordinary_codes) + 1)) - ordinary_codes)
            if missing:
                result['internal_ac_code_gaps'].append({**identity, 'codes': missing})
        for (state, name), count in names.items():
            if count > 1:
                result['same_name_different_codes'].append({**identity, 'record_state': state, 'name': name, 'count': count})
        for record in records:
            if not record.get('candidates'):
                kind['without_candidates'] += 1
                result['records_without_candidates'].append({**identity, 'code': record.get('code'),
                    'record_state': record.get('state_name') or record.get('state_code') or item['state'],
                    'name': record.get('constituency_name') or record.get('name'),
                    'source_locator': record.get('source_locator'), 'reason': record.get('error')})
        grouped[(item['kind'], item['state'], item['year'])].append(edition)
    result['year_state_editions'] = [{'kind': kind, 'state': state, 'year': year, 'edition_ids': editions}
        for (kind, state, year), editions in sorted(grouped.items(), key=lambda value: (value[0][0], value[0][1] or '', value[0][2]))]
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--detail', action='store_true', help='Print individual gap records')
    args = parser.parse_args()
    report = summarize(args.root)
    if not args.detail:
        report['records_without_candidates'] = len(report['records_without_candidates'])
        report['missing_extractions'] = len(report['missing_extractions'])
        report['duplicate_codes_within_edition'] = len(report['duplicate_codes_within_edition'])
        report['same_name_different_codes'] = len(report['same_name_different_codes'])
        report['extraction_source_mismatches'] = len(report['extraction_source_mismatches'])
        report['internal_ac_code_gaps'] = len(report['internal_ac_code_gaps'])
        report['year_state_editions'] = len(report['year_state_editions'])
    print(json.dumps(report, ensure_ascii=False, indent=2))
