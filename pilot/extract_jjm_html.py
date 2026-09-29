"""Raw cell evidence for explicitly registered public JJM report snapshots."""
import json
from datetime import datetime, timezone
from extract_mgnrega_html import parse

ORIGINAL_SHA256 = '5781f578c329a2ac4fb5d84823ea8b2ad2a67f99c4bb648f52f3b4c857d455dc'
SOURCE_URL = 'https://ejalshakti.gov.in/JJM/JJMReports/Physical/Rpt_JJM_VillageWisePWSReport.aspx'
J17_SHA256 = '345688afbccf0ea0433bade42fb1af58ba46772245b733a1b12baad951b9bea2'
J17_URL = 'https://ejalshakti.gov.in/JJM/JJMReports/Physical/JJMRep_DistrictWiseFHTCCoverage.aspx'
F26_SHA256 = '435367d063f346d17e0e5348e72a87819bf468a608b3600401031caaa80c924b'
F26_URL = 'https://ejalshakti.gov.in/JJM/JJMReports/Physical/rpt_RWS_SchoolsEntryStatus.aspx'
PROFILES = {
    ORIGINAL_SHA256: dict(source_url=SOURCE_URL, report='J1',
        heading='State wise PWS and FHTC Coverage',
        limitation='PWS, FHTC, reported HGJ and certified HGJ remain distinct'),
    J17_SHA256: dict(source_url=J17_URL, report='J17',
        heading='Analysis of tap water connections in Districts',
        limitation='State counts of districts in coverage bands are not district records; percentages are not additive'),
    F26_SHA256: dict(source_url=F26_URL, report='F26',
        heading='Status of Pipe Water Supply in School',
        limitation='School entry includes unapproved data; source Approved Entry is not Pollmedia approval. DISE, rural and entered school totals have different denominators; facilities overlap. Form selections remain in original HTML and require review; no Anganwadi or academic-year inference'),
}


def extract(root, package, expected, job, digest, resources_ok):
    from census_server_worker import ResourceWait
    profile = PROFILES.get(expected)
    if (profile is None or digest(package) != expected
            or job.get('source_url') != profile['source_url']):
        raise ValueError('Unregistered or changed JJM HTML original')
    if not resources_ok(root):
        raise ResourceWait('Waiting for disk/RAM reserve')
    source = package.read_bytes().decode('utf-8')
    if profile['heading'] not in source or 'Enter Captcha' in source:
        raise ValueError('Expected registered JJM report, not an access challenge')
    cells = parse(source)
    evidence = root / 'source-evidence'
    evidence.mkdir(exist_ok=True)
    path = evidence / ('jjm-html-' + expected + '.cells.jsonl')
    temporary = path.with_suffix('.partial')
    with temporary.open('w', encoding='utf-8', newline='\n') as output:
        for cell in cells:
            output.write(json.dumps(cell, ensure_ascii=False) + '\n')
    if not resources_ok(root):
        temporary.unlink(missing_ok=True)
        raise ResourceWait('Waiting for disk/RAM reserve')
    temporary.replace(path)
    receipt = dict(original_sha256=expected, source_url=job['source_url'], report=profile['report'],
        extracted_at=datetime.now(timezone.utc).isoformat(), encoding='utf-8',
        cells=len(cells), cells_sha256=digest(path),
        locator_definition='1-based table/row/cell in source order; 0-based character offsets in decoded original; spans remain attributes',
        review_state='PENDING ADMIN REVIEW',
        limitations=['No inferred expanded column grid or geography joins',
                    'No arithmetic or semantic validation yet',
                    'Reporting as-of date unverified; repeated totals retained',
                    profile['limitation']])
    target = evidence / ('jjm-html-' + expected + '.manifest.json')
    temporary = target.with_suffix('.tmp')
    temporary.write_text(json.dumps(receipt, indent=2), encoding='utf-8')
    temporary.replace(target)
