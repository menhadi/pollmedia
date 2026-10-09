"""Preserve the explicit 1951 Rayagada Phulbani unopposed declaration."""
import copy
from io import BytesIO
import json
import re
import zipfile
import fitz
import build_pc_successful_candidate_reviews as shared

NAME = 'pollmedia-pc-1951-rayagada-uncontested-review-20261009'
SPECS = [
    ('9a57af51e71e6ff194d3f409', 178, 'pollmedia-pc-1951-north-bengal-reviewed-members-20261004', '439e9a54fa7eb1ca9bcb04a51c16aaadec5fa5d2504e0cf1c468a2c88dba11cb', '4935e2089ec9976673d9fb88618c5ca258dec66462fe18abc3ebe77878d41d66', 393599),
]


def revised_files(root=shared.ROOT):
    fixture = json.loads((root/'application/database/fixtures/official-uncontested-results.json').read_text())
    result = []
    for eid, code, name, outer_sha, prior_sha, electors in SPECS:
        path = root/'exports'/(name+'.zip')
        if shared.digest(path.read_bytes()) != outer_sha:
            raise ValueError('Predecessor bundle differs')
        with zipfile.ZipFile(path) as outer, zipfile.ZipFile(BytesIO(outer.read('correction-'+eid+'.zip'))) as inner:
            old = inner.read('election-archive/'+eid+'/extraction.json')
        if shared.digest(old) != prior_sha:
            raise ValueError('Predecessor extraction differs')
        data = json.loads(old)
        record, = [r for r in data['records'] if r['code'] == code]
        source = fixture[eid+':'+str(code)]
        candidate, = record['candidates']
        if (record['number_of_seats'] != 1 or record['official_pc_code'] != 2
                or record['state_name'] != 'Orissa' or record['constituency_name'] != 'RAYAGADA PHULBANI (ST)'
                or record['electors'] != electors or record['summary_page'] != source['pdf_page']
                or candidate['candidate_name'] != source['candidate'] or candidate['party_at_election'] != source['party']
                or any(record[k] != 0 for k in ['votes_polled', 'valid_candidate_votes']) or candidate['votes'] != 0):
            raise ValueError('Original identity or metrics differ')
        pdf = root/'application/storage/app/private/election-archive'/eid/source['source_file']
        if pdf.is_symlink() or shared.digest(pdf.read_bytes()) != source['source_sha256']:
            raise ValueError('Official source differs')
        with fitz.open(pdf) as document:
            text = re.sub(r'\s+', ' ', document[source['pdf_page']-1].get_text(sort=True))
        for pattern in [r'STATE/UT\s*:\s*Orissa\s+CODE\s*:\s*S06',
                        r'CONSTITUENCY\s*:\s*2 - Rayagada Phulba\s+NUMBER OF SEATS\s*:\s*1',
                        r'Winner\s+INC\s+T\. SANGANA\s+Returned\s+Uncontested']:
            if re.search(pattern, text) is None:
                raise ValueError('Official declaration differs')
        record['original_extraction_warning'] = record['error']
        record['error'] = ('Official constituency summary declares '+source['candidate']+' (INC) returned uncontested. '
                           'Summary name is truncated to Rayagada Phulba; code 2, one seat, electors and candidate agree with the detailed report. Turnout and margin are not applicable; original zero placeholders and warning are preserved.')
        record['source_warning_code'] = 'official_uncontested_source_review'
        record['official_source_url'] = source['source_url']
        record['summary_source_file'] = source['source_file']
        record['summary_source_sha256'] = source['source_sha256']
        result.append((eid, old, json.dumps(data, ensure_ascii=False, indent=2).encode(), [copy.deepcopy(record)]))
    return result


def build():
    preflight = shared.preflight().replace("$result['winner_only']", "$result['uncontested']")
    preflight = preflight.replace("$record['official_successful_candidate']['winner']", "$record['candidates'][0]['candidate_name']")
    preflight = preflight.replace('winner-only', 'uncontested')
    return shared.build(name=NAME, revised_data=revised_files(), preflight_body=preflight)


if __name__ == '__main__':
    print(json.dumps(build()))
