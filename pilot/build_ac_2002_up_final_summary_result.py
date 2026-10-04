"""Preserve incomplete detail while showing Uttar Pradesh 2002 AC-403 declaration."""

import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
EDITION = '0a8d70ea7e38075bea7a6253'
NAME = 'pollmedia-ac-2002-up-final-official-result-20261004'
LIVE_SHA = '1948c51675aa00174a7987a80b465a3c2931e4c48c73720929c5780024e5431b'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3260-uttar-pradesh-2002/'
SOURCE_FILE = EDITION + '-7505.pdf'
SOURCE_SHA = 'af35765244531bbfb83eb7620325283a0e5fdcd60e6125479993c072fe1d8cf7'
PREVIOUS_NOTE = 'Official constituency summary supplies electors and voters; detailed candidate rows remain under review.'
NOTE = ('Official Uttar Pradesh 2002 constituency summary confirms turnout, winner and margin. '
        'The archived detailed report lacks the final result page; retained candidate rows total '
        '5,938 fewer votes than the printed valid total and remain under review.')


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def guarded_import_script() -> str:
    script = import_script([EDITION])
    if script.count('exec 9> .import.lock') != 1 or script.count('check_disk\nwhile IFS=') != 1:
        raise ValueError('Election import guard template changed')
    script = script.replace('exec 9> .import.lock',
                            'exec 9> /home/pollmedia/tmp/.pollmedia-election-release.lock')
    script = script.replace('check_disk\nwhile IFS=',
                            "if pgrep -af 'archive:import-json|archive:index-constituencies|[e]lection.*[o]cr'; then\n"
                            "    echo 'Another election import, index, or OCR process is active' >&2\n"
                            "    exit 1\nfi\n"
                            'check_disk\nwhile IFS=')
    return script


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (root / 'tmp/ac-2002-up-live-20261004.json').read_bytes()
    if digest(old_body) != LIVE_SHA:
        raise ValueError('Verified live 2002 predecessor differs')
    old = json.loads(old_body)
    revised = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source = [item for item in manifest['files'] if item['file'] == SOURCE_FILE]
    pdf_path = folder / SOURCE_FILE
    if (old['kind'] != 'ac' or old['year'] != 2002 or len(old['records']) != 403
            or old['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or old['source_file'] != SOURCE_FILE or old['source_sha256'] != SOURCE_SHA
            or len(source) != 1 or source[0]['sha256'] != SOURCE_SHA or pdf_path.is_symlink()
            or digest(pdf_path.read_bytes()) != SOURCE_SHA):
        raise ValueError('Official 2002 source identity differs')
    target = [record for record in revised['records'] if record['code'] == 403]
    if len(target) != 1:
        raise ValueError('AC-403 identity is ambiguous')
    record = target[0]
    if (record['name'] != 'MUZAFFARABAD' or record.get('state_name') is not None
            or record['number_of_seats'] != 1 or record['status'] != 'needs_review'
            or record['error'] != PREVIOUS_NOTE
            or record['original_extraction_warning'] !=
            'Detailed constituency totals missing; archived report ends before the final detailed page 136'
            or record['source_warning_code'] != 'official_summary_turnout_only'
            or record['summary_page'] != 442 or record['detail_page'] != 577
            or record['summary_source_file'] != SOURCE_FILE
            or record['summary_source_sha256'] != SOURCE_SHA
            or record['summary_totals'] != {'electors': 201400, 'votes_polled': 132839,
                                            'valid_candidate_votes': 132824}
            or (record['electors'], record['votes_polled']) != (201400, 132839)
            or record.get('summary_result') is not None or record.get('winner') is not None
            or record.get('margin') is not None):
        raise ValueError('Live AC-403 review state differs')
    candidate_votes = sum(row['votes'] for row in record['candidates'])
    if candidate_votes != 126886 or record['summary_totals']['valid_candidate_votes'] - candidate_votes != 5938:
        raise ValueError('Incomplete detail candidate total differs')
    with fitz.open(pdf_path) as pdf:
        if len(pdf) != 578 or 'UTTAR PRADESH' not in pdf[0].get_text().upper():
            raise ValueError('Official PDF cover does not establish jurisdiction')
        text = pdf[record['summary_page'] - 1].get_text(sort=True)
        rows = re.findall(r'^\s*(Winner|Runner up)\s*:\s*(\S+)\s+(.+?)\s+(\d+)\s*$',
                          text, re.I | re.M)
        margin = re.search(r'MARGIN\s*:\s*(\d+)', text, re.I)
        if ('Legislative Assembly of Uttar Pradesh' not in text
                or re.search(r'CONSTITUENCY\s*:\s*403\s*-\s*MUZAFFARABAD\b', text, re.I) is None
                or len(rows) != 2 or [row[0].casefold() for row in rows] != ['winner', 'runner up']
                or margin is None or (int(rows[0][3]), int(rows[1][3]), int(margin[1]))
                != (51914, 43381, 8533)):
            raise ValueError('Official AC-403 declaration differs')
        result = {'winner': rows[0][2].strip(), 'winner_party': rows[0][1].strip(),
                  'winner_votes': int(rows[0][3]), 'runner': rows[1][2].strip(),
                  'runner_party': rows[1][1].strip(), 'runner_votes': int(rows[1][3]),
                  'margin': int(margin[1])}
    if (result != {'winner': 'JAGDISH SINGH RANA', 'winner_party': 'SP',
                   'winner_votes': 51914, 'runner': 'RAOMOHD NEAIM KHAN',
                   'runner_party': 'BSP', 'runner_votes': 43381, 'margin': 8533}):
        raise ValueError('Printed AC-403 result or identity differs')
    record['previous_review_note'] = record['error']
    record['error'] = NOTE
    record['summary_result'] = result
    for before, after in zip(old['records'], revised['records'], strict=True):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != (
                {'previous_review_note', 'error', 'summary_result'} if before['code'] == 403 else set()):
            raise ValueError('Unrelated 2002 source evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    with tempfile.TemporaryDirectory(prefix='ac-2002-up-final-', dir=root / 'exports') as directory:
        temp = Path(directory)
        staged = temp / 'archive'
        packages = temp / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{LIVE_SHA}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            path = staged / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(body)
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            archive = packages / f'{kind}-{EDITION}.zip'
            package(staged, archive, 'election-archive', bucket, 8, [relative],
                    LIVE_SHA if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(archive)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as bundle:
            for archive in inner:
                bundle.write(archive, archive.name)
            bundle.writestr('SHA256SUMS', ''.join(f'{digest(archive.read_bytes())}  {archive.name}\n'
                                               for archive in inner))
            bundle.writestr('ARCHIVES', EDITION + '\n')
            bundle.writestr('AUDIT.json', json.dumps({
                'scope': 'One official 2002 Uttar Pradesh AC-403 declaration; candidate rows unchanged',
                'edition': EDITION, 'source_url': SOURCE_URL, 'source_file': SOURCE_FILE,
                'source_sha256': SOURCE_SHA, 'summary_page': 442,
                'previous_sha256': LIVE_SHA, 'new_sha256': digest(new_body),
                'detail_candidate_votes': candidate_votes, 'summary_valid_votes': 132824,
                'result': result,
            }, indent=2))
            bundle.writestr('IMPORT.sh', guarded_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'previous_sha256': LIVE_SHA,
            'new_sha256': digest(new_body), 'results': 1}


if __name__ == '__main__':
    print(json.dumps(build()))
