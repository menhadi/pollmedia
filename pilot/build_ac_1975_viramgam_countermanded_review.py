"""Document countermanding evidence without inventing a Viramgam result."""
import copy
from io import BytesIO
import json
import re
import zipfile
import fitz
import build_pc_successful_candidate_reviews as shared

NAME = 'pollmedia-ac-1975-viramgam-countermanded-review-20261009'
EDITION = '2660ede4254e7cee1c5810b4'
PRIOR = 'pollmedia-ac-1975-gujarat-declared-results-20261005'
OUTER = '13d438af7440e09c883b80aec712f8411b54e6f0b924ab6093dcd45e3f03c17d'
OLD = '30a0e00b14c2ecd66c57e4e1d50762d7d89a7a08b523cb7b2715743ff8d00b00'
PDF_SHA = '20495a62dddaf5fbb72ddcf3e7cc6e45e990032fdffe9e5dabadfe82b511bcbf'
NOTE = ('Official party-performance report states that the election for AC 63 Viramgam was countermanded '
        '(PDF page 11, printed page 9). The blank constituency summary (PDF page 77) instead labels its vote blocks '
        'Uncontested but names no winner. Both source statements are preserved; no elected candidate, votes, turnout '
        'or margin are established for this round. Original review warning retained.')


def revised_files(root=shared.ROOT):
    path = root/'exports'/(PRIOR+'.zip')
    if shared.digest(path.read_bytes()) != OUTER:
        raise ValueError('Prior bundle differs')
    with zipfile.ZipFile(path) as z, zipfile.ZipFile(BytesIO(z.read('correction-'+EDITION+'.zip'))) as q:
        old = q.read('election-archive/'+EDITION+'/extraction.json')
    if shared.digest(old) != OLD:
        raise ValueError('Prior extraction differs')
    data = json.loads(old)
    record, = [r for r in data['records'] if r['code'] == 63]
    if (record['name'] != 'VIRAMGAM' or record['state_name'] != 'Gujarat'
            or record['summary_page'] != 77 or record['candidates'] != [] or record['electors'] is not None
            or 'votes_polled' in record or 'valid_candidate_votes' in record):
        raise ValueError('Original record differs')
    pdf = root/'application/storage/app/private/election-archive'/EDITION/(EDITION+'-9027.pdf')
    if pdf.is_symlink() or shared.digest(pdf.read_bytes()) != PDF_SHA:
        raise ValueError('Official PDF differs')
    with fitz.open(pdf) as d:
        summary = re.sub(r'\s+', ' ', d[76].get_text(sort=True))
        parties = re.sub(r'\s+', ' ', d[10].get_text(sort=True))
    if ('CONSTITUENCY : 63 - VIRAMGAM' not in summary or summary.count('Uncontested') != 2
            or 'ELECTION COUNTERMANDED FOR THE AC NO. 63 ( VIRAMGAM )' not in parties):
        raise ValueError('Official contradiction differs')
    record['original_extraction_warning'] = record['error']
    record['error'] = NOTE
    record['source_warning_code'] = 'official_countermanded_blank_summary'
    record['official_source_url'] = 'https://old.eci.gov.in/files/file/3831-gujarat-1975/'
    record['summary_source_file'] = pdf.name
    record['summary_source_sha256'] = PDF_SHA
    return [(EDITION, old, json.dumps(data,ensure_ascii=False,indent=2).encode(), [copy.deepcopy(record)])]


def build():
    preflight = shared.preflight()
    start = preflight.index("        if (!($result['winner_only']")
    end = preflight.index('\n', start)
    preflight = preflight[:start] + "        if ($result !== null || $record['candidates'] !== []) { throw new RuntimeException('No named result is established'); }" + preflight[end:]
    preflight = preflight.replace('winner-only capability','blank-result exclusion')
    return shared.build(name=NAME, revised_data=revised_files(), preflight_body=preflight)


if __name__ == '__main__':
    print(json.dumps(build()))
