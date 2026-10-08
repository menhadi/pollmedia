"""Preserve the 542 PC tables and append the separate official Surat declaration."""
import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile
import fitz
from build_pc_1951_pepsu_three_summary_results import guarded_import_script
from preserve_archive_json import package

ROOT = Path(__file__).resolve().parents[1]
EDITION = '349e04305ee4652986f79497'
NAME = 'pollmedia-pc-2024-surat-unopposed-declaration-20261009'
PRIOR_SHA = '82add64dd7923f6f222013e0d6ef6ee4e1fc9e07c182671a26decdfcd9b9bc6e'
SOURCE_FILE = '46f7185d55024895c2933a0f.pdf'
SOURCE_SHA = '44e4741b6f64174bc508b499f75459e8a6721ca0efc043d8099616c5d12371f6'
SOURCE_URL = 'https://www.eci.gov.in/general-election-to-loksabha-2024-statistical-reports'
WINNER = 'MUKESHKUMAR CHANDRAKAANT DALAL'
PARTY = 'BHARATIYA JANATA PARTY'
NOTE = ('Official separate Surat report declares an unopposed winner. The main report excludes this constituency. '
        'Votes are printed as a dash; no ordinary turnout or winning margin is available. Review the linked official Report 2(A).')

def digest(body):
    return hashlib.sha256(body).hexdigest()

def verified_source(text):
    flat = re.sub(r'\s+', ' ', text)
    for token in ['ELECTIONS, 2024', '18 LOK SABHA', 'STATE/UT: GUJARAT', 'CODE: S06',
                  'CONSTITUENCY : SURAT GEN', 'CONST. NO. : 24', 'WINNER (UNOPPOSED ELECTION)']:
        if token not in flat:
            raise ValueError('Surat source identity differs: ' + token)
    if (re.search(r'4\. CONTESTED\s+1\s+0\s+0\s+1', text) is None
            or re.search(r'4\. TOTAL\s+954646\s+831563\s+78\s+1786287', text) is None
            or re.search(r'BHARATIYA JANATA\s+MUKESHKUMAR\s+WINNER \(UNOPPOSED ELECTION\)\s+-\s+PARTY\s+CHANDRAKAANT DALAL', text) is None):
        raise ValueError('Surat declaration, electors or printed dash differs')

def surat_import_script():
    script = guarded_import_script()
    marker = 'check_disk\nwhile IFS='
    if script.count(marker) != 1:
        raise ValueError('Import guard template changed')
    return script.replace(marker, "grep -Fq '349e04305ee4652986f79497:543' /home/pollmedia/app/application/database/fixtures/official-uncontested-results.json || { echo 'Pull tested Surat declaration fixture first' >&2; exit 1; }\n" + marker)

def revised_edition(root=ROOT):
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old_body = (folder / 'extraction.json').read_bytes()
    if digest(old_body) != PRIOR_SHA:
        raise ValueError('Prior PC 2024 extraction differs')
    old = json.loads(old_body)
    if (old['kind'] != 'pc' or old['year'] != 2024 or old['source_url'] != SOURCE_URL
            or len(old['records']) != 542 or {r['code'] for r in old['records']} != set(range(1,543))
            or any(r.get('state_code') == 'S06' and r.get('official_pc_code') == 24 for r in old['records'])
            or any('surat' in r['name'].lower() for r in old['records'])):
        raise ValueError('PC 2024 inventory differs or Surat already present')
    manifest = json.loads((folder/'manifest.json').read_text(encoding='utf-8'))
    if manifest['url'] != SOURCE_URL or len([f for f in manifest['files'] if f['file'] == SOURCE_FILE and f['sha256'] == SOURCE_SHA]) != 1:
        raise ValueError('Surat manifest identity differs')
    source = folder / SOURCE_FILE
    if digest(source.read_bytes()) != SOURCE_SHA:
        raise ValueError('Surat PDF checksum differs')
    with fitz.open(source) as pdf:
        if len(pdf) != 2:
            raise ValueError('Surat report page count differs')
        text = pdf[0].get_text(sort=True)
        verified_source(text)
    fixture = json.loads((root/'application/database/fixtures/official-uncontested-results.json').read_text(encoding='utf-8'))[EDITION+':543']
    if fixture != dict(name='Surat', candidate=WINNER, party=PARTY, source_url=SOURCE_URL,
                       source_file=SOURCE_FILE, source_sha256=SOURCE_SHA, pdf_page=1):
        raise ValueError('Surat application fixture differs')
    record = dict(code=543, state_name='Gujarat', state_code='S06', official_pc_code=24,
        name='Gujarat / Surat', constituency_name='Surat', number_of_seats=1,
        electors=1786287, votes_polled=None, valid_candidate_votes=None,
        status='needs_review', error=NOTE, source_warning_code='official_unopposed_declaration',
        official_source_url=SOURCE_URL, summary_source_file=SOURCE_FILE,
        summary_source_sha256=SOURCE_SHA, summary_page=1,
        summary_source_text=text,
        candidates=[dict(candidate_name=WINNER, party_at_election=PARTY, votes=None,
            general_votes=None, postal_votes=None, is_nota=False, source_page=1,
            source_values=['WINNER (UNOPPOSED ELECTION)', PARTY, WINNER, '-'])])
    after = json.loads(old_body)
    after['records'].append(record)
    if after['records'][:-1] != old['records']:
        raise ValueError('Existing constituency records changed')
    new_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, dict(edition=EDITION, year=2024, source_url=SOURCE_URL,
        source_file=SOURCE_FILE, source_sha256=SOURCE_SHA, previous_sha256=PRIOR_SHA,
        new_sha256=digest(new_body), results=[dict(code=543, official_pc_code=24, state_code='S06',
        winner=WINNER, party=PARTY, uncontested=True, page=1)], preserved_records=542)


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root)
    old_sha = digest(old_body)
    with tempfile.TemporaryDirectory(prefix='pc-2024-surat-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        inner = []
        for kind, relative, body in (
                ('snapshot', f'election-archive/{EDITION}/extraction-{old_sha}.json', old_body),
                ('correction', f'election-archive/{EDITION}/extraction.json', new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    old_sha if kind == 'correction' else None,
                    f'election-archive/{EDITION}/extraction-{old_sha}.json' if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps(detail, indent=2))
            archive.writestr('IMPORT.sh', surat_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'results': len(detail['results']),
            'previous_sha256': old_sha, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
