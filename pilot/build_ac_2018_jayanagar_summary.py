"""Recover the separate June Jayanagar declaration from a scanned appendix."""
import json
from io import BytesIO
import zipfile
import build_pc_successful_candidate_reviews as shared

NAME = 'pollmedia-ac-2018-jayanagar-june-summary-20261009'
EDITION = '76fcb8bc8d45f4e632ae1929'
PRIOR_PACKAGE = 'pollmedia-ac-summary-corrections-20261001-v7.zip'
PACKAGE_SHA = 'afa96eade332e2f2fe735cb45d5cf638012224d1b76f7fc3c3867778326cc31a'
PRIOR_SHA = '5402cabf916531c32b44bb90f898e7d540850dd305ff4d6a692653316046931e'
PDF_SHA = 'bf054e73b82202e68bde75674cd5c67eedc719e996d8408cf919e39422e44112'
URL = 'https://old.eci.gov.in/files/file/6933-karnataka-2018/'


def revised_files(root=shared.ROOT):
    package = root/'exports'/PRIOR_PACKAGE
    if shared.digest(package.read_bytes()) != PACKAGE_SHA:
        raise ValueError('Predecessor package differs')
    with zipfile.ZipFile(package) as outer, zipfile.ZipFile(BytesIO(outer.read('correction-'+EDITION+'.zip'))) as inner:
        old = inner.read('election-archive/'+EDITION+'/extraction.json')
    if shared.digest(old) != PRIOR_SHA:
        raise ValueError('Predecessor extraction differs')
    data = json.loads(old)
    if data['kind'] != 'ac' or data['year'] != 2018 or data['source_url'] != URL or len(data['records']) != 223 or any(r['code'] == 173 for r in data['records']):
        raise ValueError('Edition identity or existing coverage differs')
    pdf = root/'application/storage/app/private/election-archive'/EDITION/(EDITION+'-15997.pdf')
    if pdf.is_symlink() or shared.digest(pdf.read_bytes()) != PDF_SHA:
        raise ValueError('Visually reviewed official PDF differs')
    # Page 225 was visually verified. Its existing text layer omits the name
    # and misreads 2018 as 2013; do not use that OCR as a date authority.
    record = dict(code=173, name='Jayanagar / 11-Jun-2018', state_name='Karnataka',
        official_ac_code=173, constituency_name='Jayanagar', number_of_seats=1,
        election_round='2018-06-11', poll_date='2018-06-11', declaration_date='2018-06-13',
        electors=203187, votes_polled=111584, valid_candidate_votes=110736,
        candidates=[], status='needs_review', source_warning_code='official_summary_turnout_only',
        error='Official scanned summary page 225 declares the separate 11 June poll, with results declared 13 June 2018. It names only the winner and runner-up out of 19 contestants; a complete candidate table is unavailable here. Summary values are source-backed; candidate coverage remains incomplete.',
        candidate_coverage='summary_top_two_only', source_contested_candidates=19,
        summary_page=225, summary_source_file=pdf.name, summary_source_sha256=PDF_SHA,
        official_source_url=URL, printed_turnout_percent=54.92,
        summary_totals=dict(electors=203187, votes_polled=111584, valid_candidate_votes=110736, nota_votes=848),
        summary_result=dict(winner='Sowmya Reddy', winner_party='INC', winner_votes=54458,
            runner='B N.Prahlad', runner_party='BJP', runner_votes=51571, margin=2887))
    data['records'].append(record)
    return [(EDITION, old, json.dumps(data, ensure_ascii=False, indent=2).encode(), [record])]


def build():
    preflight = shared.preflight()
    start = preflight.index("        if (!($result['winner_only']")
    end = preflight.index('\n', start)
    preflight = preflight[:start]+"        if (($result['winner'] ?? null) !== 'Sowmya Reddy' || ($result['margin'] ?? null) !== 2887 || app(\\App\\Services\\HistoricalElectionAnalytics::class)->summarize([$record])['turnout_count'] !== 1) { throw new RuntimeException('Summary projection capability missing'); }"+preflight[end:]
    preflight = preflight.replace('winner-only capability', 'June summary capability')
    result = shared.build(name=NAME, revised_data=revised_files(), preflight_body=preflight)
    (shared.ROOT/'exports'/(NAME+'.sha256')).write_bytes((result['sha256']+'  '+NAME+'.zip\n').encode())
    return result


if __name__ == '__main__':
    print(json.dumps(build()))
