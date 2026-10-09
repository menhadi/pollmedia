"""Document the official Ankamali name without changing candidate projections."""
import json
from io import BytesIO
import zipfile
import fitz
import build_pc_successful_candidate_reviews as shared

NAME = 'pollmedia-ac-2006-ankamali-name-review-20261009'
EDITION = 'fb76cebe74b1c87526369724'
PDF_SHA = 'bae58505ed29beb58f4df2941119074b6bc1a83f7ede776f3a557bdd9c8d8d93'


def revised_files(root=shared.ROOT):
    p = root/'exports/pollmedia-ac-2006-kerala-declared-results-20261008.zip'
    if shared.digest(p.read_bytes()) != '354df2bfc8ac520c97df51799e45e6d26c018029abbfefe42af719786182943f':
        raise ValueError('Predecessor package differs')
    with zipfile.ZipFile(p) as z, zipfile.ZipFile(BytesIO(z.read('correction-'+EDITION+'.zip'))) as q:
        old = q.read('election-archive/'+EDITION+'/extraction.json')
    if shared.digest(old) != 'f6a5d2b0cbeebd39207cc32afe85753f66d5b462680dd90c07746b1492db2696':
        raise ValueError('Predecessor extraction differs')
    data = json.loads(old)
    pdf = root/'application/storage/app/private/election-archive'/EDITION/(EDITION+'-8845.pdf')
    if pdf.is_symlink() or shared.digest(pdf.read_bytes()) != PDF_SHA:
        raise ValueError('Visually verified source differs')
    with fitz.open(pdf) as doc:
        for n in [85,225]:
            text = doc[n].get_text()
            if not all(s in text for s in ['JOSE THETTAYIL','58703','52609']):
                raise ValueError('Source result differs')
    r, = [r for r in data['records'] if r['code'] == 68]
    if (data['year'] != 2006 or data['kind'] != 'ac' or r['state_name'] != 'Kerala'
            or r['name'] != 'ANKAMALI' or r['status'] != 'needs_review'
            or r['candidates'][0]['candidate_name'] != '. JOSE THETTAYIL'
            or [r['candidates'][i]['votes'] for i in [0,1]] != [58703,52609]
            or sum(c['votes'] for c in r['candidates']) != 118552
            or r['summary_totals'] != dict(electors=159574,votes_polled=118553,valid_candidate_votes=118552)):
        raise ValueError('Original identity or totals differ')
    r['previous_review_note'] = r['error']
    r.update(summary_source_file=pdf.name, summary_source_sha256=PDF_SHA,
        official_source_url='https://old.eci.gov.in/files/file/3762-kerala-2006/',
        error='Official summary page 86 and detailed page 226 both print JOSE THETTAYIL (JD(S)), 58,703 votes and a 6,094-vote margin. The extracted candidate name includes a leading row-number dot; original candidate rows and warnings are preserved. Source totals reconcile; publication review remains.',
        official_summary_name_review=dict(winner='JOSE THETTAYIL',winner_party='JD(S)',winner_votes=58703,
            runner='P.J.JOY',runner_party='INC',runner_votes=52609,margin=6094))
    return [(EDITION,old,json.dumps(data,ensure_ascii=False,indent=2).encode(),[r])]


def build():
    preflight = shared.preflight()
    start = preflight.index("        if (!($result['winner_only']")
    end = preflight.index('\n', start)
    preflight = preflight[:start]+"        if (($result['winner'] ?? null) !== '. JOSE THETTAYIL' || ($result['margin'] ?? null) !== 6094 || app(\\App\\Services\\HistoricalElectionAnalytics::class)->summarize([$record])['party_count'] !== 1) { throw new RuntimeException('Official summary capability missing'); }"+preflight[end:]
    result = shared.build(name=NAME,revised_data=revised_files(),preflight_body=preflight)
    (shared.ROOT/'exports'/(NAME+'.sha256')).write_bytes((result['sha256']+'  '+NAME+'.zip\n').encode())
    return result


if __name__ == '__main__':
    print(json.dumps(build()))
