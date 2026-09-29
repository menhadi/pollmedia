"""Raw cell evidence for the registered public JJM J1 state report."""
import json
from datetime import datetime, timezone
from extract_mgnrega_html import parse

ORIGINAL_SHA256 = '5781f578c329a2ac4fb5d84823ea8b2ad2a67f99c4bb648f52f3b4c857d455dc'
SOURCE_URL = 'https://ejalshakti.gov.in/JJM/JJMReports/Physical/Rpt_JJM_VillageWisePWSReport.aspx'


def extract(root, package, expected, job, digest, resources_ok):
    from census_server_worker import ResourceWait
    if (expected != ORIGINAL_SHA256 or digest(package) != expected
            or job.get('source_url') != SOURCE_URL):
        raise ValueError('Unregistered or changed JJM J1 HTML original')
    if not resources_ok(root):
        raise ResourceWait('Waiting for disk/RAM reserve')
    source = package.read_bytes().decode('utf-8')
    if 'State wise PWS and FHTC Coverage' not in source or 'Enter Captcha' in source:
        raise ValueError('Expected JJM J1 report, not an access challenge')
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
    receipt = dict(original_sha256=expected, source_url=job['source_url'],
        extracted_at=datetime.now(timezone.utc).isoformat(), encoding='utf-8',
        cells=len(cells), cells_sha256=digest(path),
        locator_definition='1-based table/row/cell in source order; 0-based character offsets in decoded original; spans remain attributes',
        review_state='PENDING ADMIN REVIEW',
        limitations=['No inferred expanded column grid or geography joins',
                    'No arithmetic or semantic validation yet',
                    'Reporting as-of date unverified; PWS, FHTC, reported HGJ and certified HGJ remain distinct; repeated totals retained'])
    target = evidence / ('jjm-html-' + expected + '.manifest.json')
    temporary = target.with_suffix('.tmp')
    temporary.write_text(json.dumps(receipt, indent=2), encoding='utf-8')
    temporary.replace(target)
