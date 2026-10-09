"""Preserve the explicit 1980 and 1989 Srinagar unopposed declarations."""
import copy
from io import BytesIO
import json
import re
import zipfile
import fitz
import build_pc_successful_candidate_reviews as shared

NAME = 'pollmedia-pc-srinagar-uncontested-reviews-20261009'
SPECS = [
    ('e6e3a615a5fc51328d154069', 144, 'pollmedia-pc-1980-eluru-result-20261002', '143ca59d50ef87932e149d8ca09689db1acfd34f9d22aaf1792fc78d57f2f063', '633cc98cc8e0623d51140f80c61fafd7a883aa3b748502234c1d055bd1cfc5e1', 519706),
    ('92de082304013ee1f62be87a', 142, 'pollmedia-pc-1989-discrepant-official-results-20261004', 'e893977e289699080974130a2b856c97b1b7ba564f5a517012d3fbeaca006b4c', '0f912917d468780fbf6372e95c4ff8769bcd54ea67ec6b73b2e15a92221dfa41', 782715),
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
                or record['state_name'] != 'JAMMU & KASHMIR' or record['constituency_name'] != 'SRINAGAR'
                or record['electors'] != electors or record['summary_page'] != source['pdf_page']
                or candidate['candidate_name'] != source['candidate'] or candidate['party_at_election'] != source['party']
                or any(record[k] != 0 for k in ['votes_polled', 'valid_candidate_votes']) or candidate['votes'] != 0):
            raise ValueError('Original identity or metrics differ')
        pdf = root/'application/storage/app/private/election-archive'/eid/source['source_file']
        if pdf.is_symlink() or shared.digest(pdf.read_bytes()) != source['source_sha256']:
            raise ValueError('Official source differs')
        with fitz.open(pdf) as document:
            text = re.sub(r'\s+', ' ', document[source['pdf_page']-1].get_text(sort=True))
        for pattern in [r'STATE/UT\s*:\s*JAMMU & KASHMIR\s+CODE\s*:\s*S09',
                        r'(?:CONSTITUENCY\s*:\s*SRINAGAR\s+NO\s*:\s*2|NO\s*:\s*2\s+CONSTITUENCY\s*:\s*SRINAGAR)',
                        r'Winner\s*:\s*JKN\s+'+re.escape(source['candidate'])+r'\s+Returned\s+Uncontested']:
            if re.search(pattern, text) is None:
                raise ValueError('Official declaration differs')
        record['original_extraction_warning'] = record['error']
        record['error'] = ('Official constituency summary declares '+source['candidate']+' (JKN) returned uncontested. '
                           'Turnout and margin are not applicable; original zero placeholders and review warning are preserved.')
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
