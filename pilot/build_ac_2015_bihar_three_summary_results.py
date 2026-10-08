"""Attach three Bihar 2015 official declarations without filling missing candidate cells."""
import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile
import fitz
from build_pc_1951_pepsu_three_summary_results import guarded_import_script
from extract_assembly_summary_totals import read_summary_pages
from preserve_archive_json import package

ROOT = Path(__file__).resolve().parents[1]
EDITION = '156237297630a44f74e4d401'
NAME = 'pollmedia-ac-2015-bihar-three-summary-results-20261009'
PRIOR = 'pollmedia-pc-ac-zero-turnout-corrections-20261001-v3'
PRIOR_SHA = 'ae69d7eee28f323d51fd0406e98bc6e84cea404cc3deaef5008283a05a8f7e3b'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3904-bihar-2015/'
SOURCE_FILE = EDITION + '-9231.pdf'
SOURCE_SHA = '0176a4e1efb1373a97b0fc74151397b0fa78395dc13963cc57961f1f22ba526d'
NOTE = ' Official summary declares the winner and margin; candidate vote cells remain missing and are retained for source review.'
TARGETS = {
    195: (230, 'Agiaon (SC) (SC)', 'JD(U)', 'Prabhunath Prasad', 52276, 'BJP', 'Shivesh Kumar', 37572, 14704),
    210: (245, 'Dinara', 'JD(U)', 'Jai Kumar Singh', 64699, 'BJP', 'Rajendra Prasad Singh', 62008, 2691),
    229: (264, 'Bodh Gaya (SC) (SC)', 'RJD', 'Kumar Sarvjeet', 82656, 'BJP', 'Shyamdeo Paswan', 52183, 30473),
}

def digest(body):
    return hashlib.sha256(body).hexdigest()

def declaration(text, code):
    page, name, wp, wn, wv, rp, rn, rv, margin = TARGETS[code]
    identity = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text)
    winner = re.search(r'^\s*WINNER\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.M)
    runner = re.search(r'^\s*RUNNER-UP\s+(\S+)\s+(.+?)\s+(\d+)\s*$', text, re.M)
    printed = re.search(r'^\s*MARGIN\s+(\d+)\b', text, re.M)
    if (not all((identity, winner, runner, printed)) or 'Election,2015' not in text[:220]
            or 'legislative assembly of Bihar' not in text[:220]
            or (int(identity[1]), identity[2].strip()) != (code, name)
            or (winner[1], winner[2].strip(), int(winner[3])) != (wp, wn, wv)
            or (runner[1], runner[2].strip(), int(runner[3])) != (rp, rn, rv)
            or int(printed[1]) != margin or wv - rv != margin):
        raise ValueError('Official declaration differs: ' + str(code))
    return dict(winner=wn, winner_party=wp, winner_votes=wv, runner=rn,
                runner_party=rp, runner_votes=rv, margin=margin)

def revised_edition(root=ROOT):
    path = root / 'exports' / (PRIOR + '.zip')
    if digest(path.read_bytes()) != path.with_suffix('.sha256').read_text().split()[0]:
        raise ValueError('Prior package checksum differs')
    with zipfile.ZipFile(path) as outer:
        with zipfile.ZipFile(io.BytesIO(outer.read('correction-' + EDITION + '.zip'))) as inner:
            old_body = inner.read('election-archive/' + EDITION + '/extraction.json')
    if digest(old_body) != PRIOR_SHA:
        raise ValueError('Prior extraction checksum differs')
    old = json.loads(old_body)
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / SOURCE_FILE
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if (old['kind'] != 'ac' or old['year'] != 2015 or len(old['records']) != 243
            or old['source_url'] != SOURCE_URL or manifest['url'] != SOURCE_URL
            or old['source_file'] != SOURCE_FILE or old['source_sha256'] != SOURCE_SHA
            or digest(source.read_bytes()) != SOURCE_SHA
            or len([f for f in manifest['files'] if f['file'] == SOURCE_FILE and f['sha256'] == SOURCE_SHA]) != 1):
        raise ValueError('Bihar source identity differs')
    summaries = read_summary_pages(source)
    after = json.loads(old_body)
    results = []
    with fitz.open(source) as pdf:
        for r in after['records']:
            code = r['code']
            if code not in TARGETS:
                continue
            page, name, *_ = TARGETS[code]
            summary = summaries[code]
            if (r['name'] != name or r['state_name'] != 'Bihar' or r['number_of_seats'] != 1
                    or r['status'] != 'needs_review' or r.get('summary_result') is not None
                    or r['summary_page'] != page or summary['summary_page'] != page
                    or r['summary_source_file'] != SOURCE_FILE or r['summary_source_sha256'] != SOURCE_SHA
                    or r['source_warning_code'] != 'official_summary_turnout_only'
                    or not r.get('original_extraction_warning') or not r['candidates']
                    or any(c.get('votes') is not None for c in r['candidates'])
                    or r['summary_totals'] != {k:summary[k] for k in ('electors','votes_polled','valid_candidate_votes')}
                    or r['electors'] != summary['electors'] or r['votes_polled'] != summary['votes_polled']):
                raise ValueError('Prior Bihar record differs: ' + str(code))
            result = declaration(pdf[page-1].get_text(sort=True), code)
            if not 0 < result['runner_votes'] < result['winner_votes'] <= summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']:
                raise ValueError('Official totals out of range')
            r['summary_result'] = result
            r['error'] += NOTE
            results.append(dict(code=code, page=page, **result))
    if {r['code'] for r in results} != set(TARGETS):
        raise ValueError('Target coverage differs')
    for before, revised in zip(old['records'], after['records']):
        changed = {k for k in set(before) | set(revised) if before.get(k) != revised.get(k)}
        if changed != ({'summary_result','error'} if before['code'] in TARGETS else set()):
            raise ValueError('Unrelated evidence changed')
    body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, body, dict(edition=EDITION, year=2015, state='Bihar', source_url=SOURCE_URL,
        source_file=SOURCE_FILE, source_sha256=SOURCE_SHA, previous_sha256=PRIOR_SHA,
        new_sha256=digest(body), results=results, prior_packages=[PRIOR + '.zip'])


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, detail = revised_edition(root)
    old_sha = digest(old_body)
    with tempfile.TemporaryDirectory(prefix='ac-2015-bihar-results-', dir=output.parent) as temporary:
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
            archive.writestr('IMPORT.sh', guarded_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'results': len(detail['results']),
            'previous_sha256': old_sha, 'new_sha256': detail['new_sha256']}


if __name__ == '__main__':
    print(json.dumps(build()))
