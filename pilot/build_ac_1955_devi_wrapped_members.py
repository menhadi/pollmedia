"""Preserve DEVI's two source-printed declarations, including a wrapped name."""
import copy
from io import BytesIO
import json
import re
import zipfile
import fitz
import build_ac_1954_1955_multi_seat_members as base

shared = base.shared
NAME = 'pollmedia-ac-1955-devi-wrapped-members-20261009'
EDITION = base.SPECS[0][0]
PRIOR = '9b0fc803122905e6729ab1ec473db94324d5d3840a5bf2c682ba47f8c7c1e85e'
OUTER = 'b9ceaedac3d3175bddcc65f505e485186c6427bcb622a1b3724a02028200e945'


def unwrap(text):
    pattern = r'(Winner\s+1\s+INC\s+)(MALLEPUDI RAJESWARA RAO YARLAGADDA SIVA RAMA)61128\s*\n\s*(PRASAD BAHADUR GARU)'
    fixed, count = re.subn(pattern, r'\1\2 \3 61128', text)
    if count != 1:
        raise ValueError('Exact source wrapping differs')
    return fixed


def revised_files(root=shared.ROOT):
    p = root/'exports'/(base.NAME+'.zip')
    if shared.digest(p.read_bytes()) != OUTER:
        raise ValueError('Prior package checksum differs')
    with zipfile.ZipFile(p) as z, zipfile.ZipFile(BytesIO(z.read('correction-'+EDITION+'.zip'))) as q:
        old = q.read('election-archive/'+EDITION+'/extraction.json')
    if shared.digest(old) != PRIOR:
        raise ValueError('Prior bytes differ')
    data = json.loads(old)
    record, = [r for r in data['records'] if r['code'] == 80]
    pdf = root/'application/storage/app/private/election-archive'/EDITION/(EDITION+'-9588.pdf')
    if pdf.is_symlink() or shared.digest(pdf.read_bytes()) != base.SPECS[0][5]:
        raise ValueError('Official PDF differs')
    if record['state_name'] != 'Andhra Pradesh' or record['status'] != 'needs_review' or record.get('official_multi_seat_winners') is not None:
        raise ValueError('Original review state differs')
    with fitz.open(pdf) as doc:
        summary = doc[92].get_text(sort=True)
        detail = doc[191].get_text(sort=True)
    if '1955' not in summary or 'Legislative Assembly of Andhra Pradesh' not in summary:
        raise ValueError('Source jurisdiction/year differs')
    name, totals, members = base.declarations(unwrap(summary), record)
    block = re.search(r'Constituency\s*:\s*80 DEVI\b(.*?)Constituency\s*:\s*81', detail, re.S)
    if not block or not re.search(r'MALLEPUDI RAJESWARA RAO YARLAGADDA SIVA RAMA\s+M\s+INC\s+61128\s+30\.33%\s+PRASAD BAHADUR GARU', block[1]) or not re.search(r'2\s*\.\s*SRIMANTH RAJA\s+M\s+INC\s+58374', block[1]):
        raise ValueError('Detailed source corroboration differs')
    record['original_extraction_warning'] = record['error']
    record['error'] = ('The official summary names both elected members; the first source-printed name wraps onto a second line and is corroborated by detailed PDF page 192. The full printed name and original candidate rows are retained. Votes across two seats are not ordinary turnout; no single winner or margin is inferred.')
    record.update(source_warning_code='official_multi_seat_summary', summary_page=93, summary_totals=totals, summary_source_file=pdf.name, summary_source_sha256=base.SPECS[0][5], detail_source_file=pdf.name, detail_source_sha256=base.SPECS[0][5], official_source_url=base.SPECS[0][7], official_summary_constituency_name=name, official_multi_seat_winners=members)
    return [(EDITION, old, json.dumps(data, ensure_ascii=False, indent=2).encode(), [copy.deepcopy(record)])]


def build():
    preflight = shared.preflight()
    start = preflight.index("        if (!($result['winner_only']")
    end = preflight.index('\n', start)
    preflight = preflight[:start]+"        if ($result !== null || count(app(\\App\\Services\\HistoricalElectionAnalytics::class)->multiSeatDeclaredWinners($record) ?? []) !== 2) { throw new RuntimeException('Two-seat projection capability missing'); }"+preflight[end:]
    preflight = preflight.replace('winner-only capability', 'two-seat declarations')
    result = shared.build(name=NAME, revised_data=revised_files(), preflight_body=preflight)
    (shared.ROOT/'exports'/(NAME+'.sha256')).write_bytes((result['sha256']+'  '+NAME+'.zip\n').encode())
    return result


if __name__ == '__main__':
    print(json.dumps(build()))
