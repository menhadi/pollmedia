"""Compare 1974 Uttar Pradesh AC declarations to retained detailed candidate rows."""

import hashlib
import json
from pathlib import Path
import zipfile

import fitz

from audit_pc_ac_zero_values import correction_index, effective_body
from build_ac_1996_assam_summary_discrepancy_bundle import verified_summary
from extract_assembly_summary_totals import read_summary_pages


ROOT = Path(__file__).resolve().parents[1]
EDITION = '0568bae81d96e55877d1807e'
PRIOR_PACKAGE = 'pollmedia-ac-candidate-count-turnout-20261002.zip'
PRIOR_SHA256 = 'e528cf550816671b1f230aa7bc4fbb86c459b922cd4f01417d38e83fc22b7a1b'


def audit(root: Path = ROOT) -> tuple[bytes, list[dict], dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    revisions = correction_index(root, (PRIOR_PACKAGE,))
    if len(revisions.get(EDITION, [])) != 1:
        raise ValueError('Prior candidate-count turnout correction missing')
    old_body = effective_body(folder / 'extraction.json', revisions[EDITION])
    old_sha = hashlib.sha256(old_body).hexdigest()
    if old_sha != PRIOR_SHA256:
        raise ValueError('Prior 1974 extraction checksum differs')
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source = next(item for item in manifest['files'] if item['file'] == data['source_file'])
    source_path = folder / source['file']
    if (data['kind'] != 'ac' or data['year'] != 1974 or data['source_url'] != manifest['url']
            or data['source_sha256'] != source['sha256']
            or hashlib.sha256(source_path.read_bytes()).hexdigest() != source['sha256']):
        raise ValueError('Official 1974 source identity differs')
    with zipfile.ZipFile(root / 'exports' / PRIOR_PACKAGE) as prior:
        entry = next(row for row in json.loads(prior.read('AUDIT.json'))['editions'] if row['edition'] == EDITION)
    codes = set(entry['shown_turnout_codes'])
    if len(codes) != 32 or entry['new_sha256'] != old_sha:
        raise ValueError('Prior corrected record inventory differs')
    summaries = read_summary_pages(source_path)
    if len(summaries) != len(data['records']) or set(summaries) != {r['code'] for r in data['records']}:
        raise ValueError('Official 1974 summary coverage differs')
    results = []
    with fitz.open(source_path) as pdf:
        for record in data['records']:
            if record['code'] not in codes:
                continue
            summary = summaries[record['code']]
            if (record['source_warning_code'] != 'official_summary_turnout_only'
                    or record['original_extraction_warning'] != 'Candidate count differs from summary'
                    or record['summary_totals'] != {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
                    or record['summary_source_sha256'] != source['sha256']
                    or record.get('summary_result') is not None):
                raise ValueError('Candidate-count reviewed record differs: ' + str(record['code']))
            result, discrepancy = verified_summary(record, summary, pdf[summary['summary_page'] - 1].get_text(sort=True))
            candidates = sorted(record['candidates'], key=lambda row: row['votes'], reverse=True)
            if discrepancy is not None or sum(row['votes'] for row in candidates) != summary['valid_candidate_votes'] \
                    or len(candidates) < 2 or candidates[0]['votes'] <= candidates[1]['votes'] \
                    or any((candidates[index]['candidate_name'], candidates[index]['party_at_election'], candidates[index]['votes'])
                           != (result[label], result[label + '_party'], result[label + '_votes'])
                           for index, label in enumerate(('winner', 'runner'))):
                raise ValueError('Official result differs from candidate detail: ' + str(record['code']))
            results.append({'code': record['code'], 'name': record['name'], 'summary_page': summary['summary_page'],
                            'result': result})
    if len(results) != 32:
        raise ValueError('1974 candidate-count result coverage differs')
    return old_body, results, {'source_url': data['source_url'], 'source_file': source['file'],
                               'source_sha256': source['sha256'], 'previous_sha256': old_sha}


if __name__ == '__main__':
    _, results, source = audit()
    print(json.dumps({'verified': len(results), 'source': source, 'codes': [r['code'] for r in results]}))
