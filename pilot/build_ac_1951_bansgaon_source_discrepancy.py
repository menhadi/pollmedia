"""Retain Bansgaon source declarations without inferring its seat count."""
import copy
from io import BytesIO
import json
import re
import zipfile
import fitz
import build_pc_successful_candidate_reviews as shared

NAME = 'pollmedia-ac-1951-bansgaon-source-discrepancy-20261009'
EDITION = '402db61ff727c908b4ac3170'
PRIOR = 'pollmedia-ac-1951-up-multi-seat-declared-members-20261004'
OUTER = '6b791cff32920e80d7328ce7be3aa93280587483aa0077ca4155751d7805cbd1'
OLD = '319b59ebfad04c79ee14c670c1ac2366f41b25c1bc4c4047f0311c8712d1411c'
PDF_SHA = '3c2014c43fcc0c5c4636c84fd43cf6d7a68d967736e3f60d44924a7f641bfdb7'
NOTE = ('Official summary names BHAGWATI (INC), 19,244 votes, and runner-up KAMLA SINGH (HMS), 2,616 votes; printed margin 16,628. '
        'Both summary and detail print zero seats. Summary turnout is #Div/0!; detail prints 34.94%. '
        'Seat count remains unresolved, so ordinary one-seat results and charts are withheld. '
        'Summary name is truncated; original candidate rows and warning are preserved.')


def revised_files(root=shared.ROOT):
    path = root/'exports'/(PRIOR+'.zip')
    if shared.digest(path.read_bytes()) != OUTER:
        raise ValueError('Prior bundle differs')
    with zipfile.ZipFile(path) as z, zipfile.ZipFile(BytesIO(z.read('correction-'+EDITION+'.zip'))) as q:
        old = q.read('election-archive/'+EDITION+'/extraction.json')
    if shared.digest(old) != OLD:
        raise ValueError('Prior extraction differs')
    data = json.loads(old)
    record, = [r for r in data['records'] if r['code'] == 300]
    if (record['name'] != 'BANSGAON EAST CUM GORAKHPUR SOUTH' or record['number_of_seats'] != 0
            or record['summary_page'] != 320 or record['detail_page'] != 429
            or [(c['candidate_name'],c['party_at_election'],c['votes']) for c in record['candidates'][:2]]
            != [('BHAGWATI','INC',19244),('KAMLA SINGH','HMS',2616)]):
        raise ValueError('Original record differs')
    pdf = root/'application/storage/app/private/election-archive'/EDITION/(EDITION+'-7462.pdf')
    if pdf.is_symlink() or shared.digest(pdf.read_bytes()) != PDF_SHA:
        raise ValueError('Official PDF differs')
    with fitz.open(pdf) as d:
        summary = re.sub(r'\s+', ' ', d[319].get_text(sort=True))
        detail = re.sub(r'\s+', ' ', d[428].get_text(sort=True))
    for value in ['300 - BANSGAON EAST CUM GORAKHPUR S', 'NUMBER OF SEATS : 0',
                  'Winner INC BHAGWATI 19244', 'Runner up HMS KAMLA SINGH 2616',
                  'MARGIN : 16628', '#Div/0!', '85232', '29782']:
        if value not in summary:
            raise ValueError('Official summary differs')
    if not re.search(r'Constituency 300 .*?OF SEATS 0 .*?BHAGWATI.*?19244.*?KAMLA SINGH.*?2616.*?34.94%',detail):
        raise ValueError('Official detail differs')
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_zero_seat_count_discrepancy'
    record['official_source_url'] = 'https://old.eci.gov.in/files/file/3241-uttar-pradesh-1951/'
    record['summary_source_file'] = pdf.name
    record['summary_source_sha256'] = PDF_SHA
    return [(EDITION, old, json.dumps(data,ensure_ascii=False,indent=2).encode(), [copy.deepcopy(record)])]


def build():
    preflight = shared.preflight()
    start = preflight.index("        if (!($result['winner_only']")
    end = preflight.index('\n', start)
    preflight = preflight[:start] + "        if ($result !== null || $record['number_of_seats'] !== 0) { throw new RuntimeException('Unresolved seat count must stay excluded'); }" + preflight[end:]
    preflight = preflight.replace('winner-only capability','unresolved-seat exclusion')
    return shared.build(name=NAME, revised_data=revised_files(), preflight_body=preflight)


if __name__ == '__main__':
    print(json.dumps(build()))
