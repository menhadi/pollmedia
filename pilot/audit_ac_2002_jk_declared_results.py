"""Check official 2002 Jammu & Kashmir declarations with reviewed detail rows."""

import hashlib
import json
from pathlib import Path
import re

import fitz

from audit_pc_ac_zero_values import correction_index, effective_body
from build_pc_1989_summary_result_bundle import source_totals
from build_pc_1992_summary_result_bundle import normalized, printed_candidate


ROOT = Path(__file__).resolve().parents[1]
EDITION = '6210f814ee5b46e924395875'
PRIOR_PACKAGES = tuple(f'pollmedia-pc-ac-zero-turnout-corrections-20261001-v{i}.zip' for i in (1, 2, 3))
PRIOR_SHA256 = 'f97b40f5b8f568583a107dba448cd2445dc9fe934d5f2dc405c96c17965a280a'
SOURCE_NOTE = 'Official constituency summary supplies electors and voters; detailed candidate rows remain under review.'


def audit(root: Path = ROOT) -> tuple[bytes, list[dict], dict]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    revisions = correction_index(root, PRIOR_PACKAGES)
    if len(revisions.get(EDITION, [])) != 3:
        raise ValueError('Prior 2002 Jammu & Kashmir revisions are missing')
    old_body = effective_body(folder / 'extraction.json', revisions[EDITION])
    if hashlib.sha256(old_body).hexdigest() != PRIOR_SHA256:
        raise ValueError('Prior 2002 extraction checksum differs')
    data = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    files = [row for row in manifest['files'] if row['file'] == data['source_file']]
    pdf_path = folder / data['source_file']
    if (data['kind'] != 'ac' or data['year'] != 2002 or data['source_url'] != manifest['url']
            or len(files) != 1 or files[0]['sha256'] != data['source_sha256']
            or hashlib.sha256(pdf_path.read_bytes()).hexdigest() != files[0]['sha256']):
        raise ValueError('Official source identity differs')
    targets = [r for r in data['records'] if r.get('error') == SOURCE_NOTE]
    if len(targets) != 85:
        raise ValueError('Reviewed 2002 constituency inventory differs')
    results, detail_held = [], []
    with fitz.open(pdf_path) as pdf:
        for record in targets:
            page_number = record.get('summary_page')
            if (record.get('number_of_seats') != 1 or record.get('state_name') != 'Jammu & Kashmir'
                    or record.get('source_warning_code') != 'official_summary_turnout_only'
                    or not isinstance(page_number, int) or not 1 <= page_number <= len(pdf)):
                raise ValueError('Reviewed 2002 record identity differs: ' + str(record['code']))
            text = pdf[page_number - 1].get_text(sort=True)
            heading = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text, re.I)
            electors = re.search(r'II\. ELECTORS\b(.*?)III\. ELECTORS WHO VOTED\b', text, re.I | re.S)
            voters = re.search(r'III\. ELECTORS WHO VOTED\b(.*?)IV\. VOTES\b', text, re.I | re.S)
            valid = re.search(r'2\. VALID\s+(\d+)', text, re.I)
            margin = re.search(r'MARGIN\s*:\s*(\d+)', text, re.I)
            if (not all((heading, electors, voters, valid, margin))
                    or 'State Election, 2002 to the Legislative Assembly of JAMMU & KASHMIR' not in text
                    or int(heading[1]) != record['code'] or normalized(heading[2]) != normalized(record['name'])):
                raise ValueError('Official summary identity differs: ' + str(record['code']))
            totals = (source_totals(electors[1]), source_totals(voters[1]), int(valid[1]))
            if (totals != tuple(record['summary_totals'][key] for key in ('electors', 'votes_polled', 'valid_candidate_votes'))
                    or not 0 < totals[2] <= totals[1] <= totals[0]):
                raise ValueError('Official summary totals differ: ' + str(record['code']))
            rows = re.findall(r'^\s*(Winner|Runner up)\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
            if (len(rows) != 2 or rows[0][0].casefold() != 'winner' or rows[1][0].casefold() != 'runner up'
                    or int(rows[0][3]) <= int(rows[1][3])
                    or int(rows[0][3]) - int(rows[1][3]) != int(margin[1])
                    or int(rows[0][3]) > totals[2]):
                raise ValueError('Official declared result differs: ' + str(record['code']))
            result = {'winner': rows[0][2].strip(), 'winner_party': rows[0][1].strip(),
                      'winner_votes': int(rows[0][3]), 'runner': rows[1][2].strip(),
                      'runner_party': rows[1][1].strip(), 'runner_votes': int(rows[1][3]),
                      'margin': int(margin[1])}
            ranked = sorted(record['candidates'], key=lambda row: row['votes'], reverse=True)
            detail_matches = (len(ranked) >= 2 and ranked[0]['votes'] > ranked[1]['votes']
                              and int(margin[1]) == ranked[0]['votes'] - ranked[1]['votes']
                              and printed_candidate(text, 'Winner', ranked[0])
                              and printed_candidate(text, 'Runner up', ranked[1]))
            if not detail_matches:
                detail_held.append(record['code'])
            results.append({'code': record['code'], 'name': record['name'], 'summary_page': page_number,
                            'detail_matches': detail_matches, 'result': result})
    if len(results) != 85 or detail_held != [14, 49]:
        raise ValueError('Official 2002 result/detail coverage differs')
    return old_body, results, {'source_url': data['source_url'], 'source_file': data['source_file'],
                               'source_sha256': data['source_sha256'], 'previous_sha256': PRIOR_SHA256,
                               'detail_held': detail_held}


if __name__ == '__main__':
    _, results, source = audit()
    print(json.dumps({'verified': len(results), 'detail_held': source['detail_held'],
                      'codes': [item['code'] for item in results]}))
