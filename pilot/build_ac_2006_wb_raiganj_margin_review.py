"""Show Raiganj's declared winner with a clearly marked source-margin conflict."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from audit_ac_2006_wb_declared_results import EDITION, ROOT, SOURCE_NOTE
from build_ac_2006_wb_declared_results import revised_edition as prior_revision
from build_pc_1992_summary_result_bundle import normalized
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


NAME = 'pollmedia-ac-2006-wb-raiganj-margin-review-20261003'
PRIOR_SHA256 = '55c2a2a0d874a3c4bf2962578765d32a232aa6158f68f28d4b40d6bf9a251b23'
NOTE = (SOURCE_NOTE + ' The report prints a margin of 16,103, but its declared winner and runner '
        'votes differ by 15,760. The displayed margin is calculated from those reported votes.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    _, old_body, previous = prior_revision(root)
    if (digest(old_body) != PRIOR_SHA256
            or previous['source']['held'] != [{'code': 31, 'printed_margin': 16103,
                                               'candidate_difference': 15760}]):
        raise ValueError('Prior West Bengal 2006 correction differs')
    old = json.loads(old_body)
    revised = json.loads(old_body)
    source = next(row for row in old['records'] if row['code'] == 31)
    target = next(row for row in revised['records'] if row['code'] == 31)
    if (source['name'] != 'RAIGANJ (SC)' or source['state_name'] != 'West Bengal'
            or source['number_of_seats'] != 1 or source['status'] != 'needs_review'
            or source['source_warning_code'] != 'official_summary_turnout_only'
            or source['error'] != SOURCE_NOTE or source.get('summary_result') is not None
            or source.get('winner') is not None or source.get('margin') is not None
            or source['summary_page'] != 56
            or source['summary_totals'] != {'electors': 204055, 'votes_polled': 166755,
                                            'valid_candidate_votes': 166576}):
        raise ValueError('Raiganj source record differs')
    pdf_path = root / 'application/storage/app/private/election-archive' / EDITION / old['source_file']
    if (digest(pdf_path.read_bytes()) != old['source_sha256']
            or old['source_url'] != 'https://old.eci.gov.in/files/file/3194-west-bengal-2006/'):
        raise ValueError('Raiganj official report differs')
    with fitz.open(pdf_path) as pdf:
        text = pdf[55].get_text(sort=True)
    heading = re.search(r'CONSTITUENCY\s+(\d+)\s*-\s*([^\n]+)', text, re.I)
    winner = re.search(r'^\s*WINNER\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    runner = re.search(r'^\s*RUNNER-UP\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.I | re.M)
    margin = re.search(r'^\s*MARGIN\s+(\d+)\b', text, re.I | re.M)
    if (not all((heading, winner, runner, margin))
            or 'legislative assembly of  West Bengal' not in text
            or int(heading[1]) != 31 or normalized(heading[2]) != normalized(source['name'])
            or int(winner[3]) != 77789 or int(runner[3]) != 62029 or int(margin[1]) != 16103
            or normalized(winner[2]) != normalized(source['candidates'][0]['candidate_name'])
            or normalized(runner[2]) != normalized(source['candidates'][1]['candidate_name'])
            or winner[1] != source['candidates'][0]['party_at_election']
            or runner[1] != source['candidates'][1]['party_at_election']
            or int(winner[3]) != source['candidates'][0]['votes']
            or int(runner[3]) != source['candidates'][1]['votes']):
        raise ValueError('Raiganj official declaration or candidate details differ')
    difference = int(winner[3]) - int(runner[3])
    if difference != 15760 or difference == int(margin[1]):
        raise ValueError('Raiganj margin discrepancy differs')
    target['error'] = NOTE
    target['summary_reported_margin'] = int(margin[1])
    target['summary_result'] = {'winner': winner[2].strip(), 'winner_party': winner[1].strip(),
                                'winner_votes': int(winner[3]), 'runner': runner[2].strip(),
                                'runner_party': runner[1].strip(), 'runner_votes': int(runner[3]),
                                'margin': difference}
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != ({'error', 'summary_result', 'summary_reported_margin'}
                                                         if before['code'] == 31 else set()):
            raise ValueError('Unrelated 2006 constituency evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 2006, 'code': 31,
                                'source_url': old['source_url'], 'source_sha256': old['source_sha256'],
                                'summary_page': 56, 'previous_sha256': digest(old_body),
                                'new_sha256': digest(new_body), 'printed_margin': 16103,
                                'candidate_difference': difference}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root)
    old_sha = detail['previous_sha256']
    with tempfile.TemporaryDirectory(prefix='ac-2006-wb-raiganj-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{old_sha}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    old_sha if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Raiganj 2006 source-margin discrepancy; winner and calculated margin shown for review',
                                                      **detail}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, **detail}


if __name__ == '__main__':
    print(json.dumps(build()))
