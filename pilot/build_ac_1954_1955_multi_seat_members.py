"""Source-check 51 historical two-seat declarations; preserve DEVI for review."""
import copy
from io import BytesIO
import json
import re
import zipfile
import fitz
import build_pc_successful_candidate_reviews as shared
from build_ac_1957_kerala_multi_seat_declared_members import source_total, norm

NAME = 'pollmedia-ac-1954-1955-51-multi-seat-members-20261009'
# Exact edition metadata and source headings, including official spelling.
SPECS = [
 ('165392d9f968ef073166ef32', 1955, 'Andhra Pradesh', 'Andhra Pradesh', '9588', 'b087d7c7f4391e0a6c9b60cbd4cda3c1562d70a29e5b1a85c4723caa18b22255', '9b26c9db510f98530098a914f86670e06577fa957e12494fd9dc12098ea3ddb9', 'https://old.eci.gov.in/files/file/4042-andhra-pradesh-1955/', 29),
 ('735cfaffb27c8b042f37a1fe', 1954, 'Travancore Cochin', 'Travancore - Cochine', '9700', '0ca898f740f3b7c5de26d38e34f43e2f9ca259d989a9554485cf8350d778cf4c', '7d963b8b7b343a001b02687bb7c935d91dd6149b097d0f929969c564bbe65939', 'https://old.eci.gov.in/files/file/4094-travancore-cochin-1954/', 11),
 ('8e6378352da06885444d7011', 1954, 'Patiala & East Punjab States Union', 'Patiala & East Pujab States Union', '9706', '3074439e080b5d450409e0e707b82576b3a4ef75beab45414a638b3ca709d611', 'fed3682e2c0fe8dfcc88acb400048bedf52ce1a379c6a104414310154354d935', 'https://old.eci.gov.in/files/file/4097-patiala-east-punjab-states-union-1954/', 12),
]


def declarations(text, record):
    seat = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*(.*?)\s+NUMBER OF SEATS\s*:\s*(\d+)', text, re.S | re.I)
    if not seat or int(seat[1]) != record['code'] or int(seat[3]) != 2 or record['number_of_seats'] != 2 or norm(seat[2]) != norm(record['name']):
        raise ValueError('Summary identity differs')
    electors = source_total(text, r'II\. ELECTORS', r'III\. ELECTORS WHO VOTED')
    voters = source_total(text, r'III\. ELECTORS WHO VOTED', r'IV\. VOTES')
    section = re.search(r'IV\. VOTES\b(.*?)VI\. DATES', text, re.S)
    polled = int(re.search(r'1\. POLLED\s+(\d+)', section[1])[1])
    valid = int(re.search(r'2\. VALID\s+(\d+)', section[1])[1])
    if (electors, voters, polled, valid) != (record['electors'], record['votes_polled'], record['votes_polled'], record['valid_candidate_votes']) or sum(c['votes'] for c in record['candidates']) != valid:
        raise ValueError('Source totals differ')
    result = re.search(r'VII\. RESULT\b(.*?)rptConstituencySummary', text, re.S)
    found = re.findall(r'^\s*Winner\s+(\d+)\s+(\S+)\s+(.+?)\s+(\d+)\s*$', result[1], re.M)
    if [int(w[0]) for w in found] != [1, 2]:
        raise ValueError('Complete member list not established')
    members = []
    for _, party, name, votes in found:
        if sum((c['candidate_name'], c['party_at_election'], c['votes']) == (name.strip(), party, int(votes)) for c in record['candidates']) != 1:
            raise ValueError('Declared member differs from candidate row')
        members.append({'name': name.strip(), 'party': party, 'votes': int(votes)})
    if members[0] == members[1]:
        raise ValueError('Duplicate declaration')
    return seat[2].strip(), {'electors': electors, 'votes_polled': voters, 'valid_candidate_votes': valid}, members


def revised_files(root=shared.ROOT):
    revised = []
    for eid, year, state, heading, suffix, pdf_sha, prior_sha, url, count in SPECS:
        folder = root/'application/storage/app/private/election-archive'/eid
        old = (folder/'extraction.json').read_bytes()
        if eid == SPECS[0][0]:
            p = root/'exports/pollmedia-ac-andhra-1955-sattenpalli-invalid-turnout-result-20261004.zip'
            if shared.digest(p.read_bytes()) != p.with_suffix('.sha256').read_text().split()[0]:
                raise ValueError('Prior bundle receipt conflict')
            with zipfile.ZipFile(p) as z, zipfile.ZipFile(BytesIO(z.read('correction-'+eid+'.zip'))) as q:
                old = q.read('election-archive/'+eid+'/extraction.json')
        pdf = folder/(eid+'-'+suffix+'.pdf')
        if shared.digest(old) != prior_sha or pdf.is_symlink() or shared.digest(pdf.read_bytes()) != pdf_sha:
            raise ValueError('Prior extraction or source checksum conflict')
        data = json.loads(old)
        if (data['kind'], data['year'], data['source_url'], data['source_file'], data['source_sha256']) != ('ac', year, url, pdf.name, pdf_sha):
            raise ValueError('Source metadata conflict')
        targets = {r['code']: r for r in data['records'] if r.get('number_of_seats') == 2}
        if len(targets) != count:
            raise ValueError('Two-seat coverage differs')
        if eid == SPECS[0][0]:
            del targets[80]  # Wrapped DEVI declaration needs separate visual review.
        seen = set(); samples = []
        with fitz.open(pdf) as doc:
            for page in range(len(doc)):
                text = doc[page].get_text(sort=True)
                if 'CONSTITUENCY DATA - SUMMARY' not in text:
                    continue
                match = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-', text)
                if not match or int(match[1]) not in targets:
                    continue
                code = int(match[1]); r = targets[code]
                if code in seen or r['state_name'] != state or r['status'] != 'needs_review' or r.get('official_multi_seat_winners') is not None:
                    raise ValueError('Target identity or state differs')
                compact = re.sub(r'\s+', ' ', text)
                if 'Legislative Assembly of '+heading not in compact or str(year) not in compact.split('CONSTITUENCY DATA')[0]:
                    raise ValueError('Official jurisdiction/year differs')
                name, totals, members = declarations(text, r)
                r['original_extraction_warning'] = r['error']
                r['error'] = 'The official constituency summary names both elected members. Votes across two seats are not ordinary one-seat turnout; original candidate rows and extraction warning are retained.'
                r.update(source_warning_code='official_multi_seat_summary', summary_page=page+1, summary_totals=totals, summary_source_file=pdf.name, summary_source_sha256=pdf_sha, official_source_url=url, official_summary_constituency_name=name, official_multi_seat_winners=members)
                samples.append(copy.deepcopy(r)); seen.add(code)
        if seen != set(targets):
            raise ValueError('Missing source declaration')
        revised.append((eid, old, json.dumps(data, ensure_ascii=False, indent=2).encode(), samples))
    return revised


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
