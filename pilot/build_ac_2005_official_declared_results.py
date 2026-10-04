"""Package 30 printed 2005 AC declarations over incomplete candidate rows."""

import csv
import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from build_pc_ac_zero_turnout_bundle import import_script, normalized
from extract_assembly_summary_totals import read_summary_pages
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
NAME = 'pollmedia-ac-2005-official-declared-results-20261004'
NOTE = ('Official constituency summary confirms the declared winner and margin; '
        'detailed candidate rows remain under review. See the official source file.')
PREVIOUS_NOTE = ('Official constituency summary supplies electors and voters; '
                 'detailed candidate rows remain under review.')
EDITIONS = {
    '22d3dca0090df0f6532ee43b': {
        'state': 'Jharkhand', 'file_suffix': '8912',
        'source_url': 'https://old.eci.gov.in/files/file/3785-jharkhand-2005/',
        'source_sha256': '56364122b1469019673f2c2301b26e668b2d4cf660352bb0fc31f6670c04c1f0',
        'live_file': 'ac-2005-jharkhand-live-20261004.json',
        'live_sha256': '64e14bdf28676c1d5dd37c7c261473100bd9692e13a80d841a6988b41fc65629',
        'codes': {7, 8, 9, 10, 13, 15, 19, 20, 22, 23, 24, 28, 29, 30, 31, 34, 35,
                  38, 39, 40, 42, 61, 66, 72, 73, 75, 80, 81},
    },
    '9cb8816f5dd2073708040572': {
        'state': 'Haryana', 'file_suffix': '9015',
        'source_url': 'https://old.eci.gov.in/files/file/3825-haryana-2005/',
        'source_sha256': 'b48a3ff7b4dbb7876aa8a6348e9e74347b60a0ff1c783abb2934577425160fcc',
        'live_file': 'ac-2005-haryana-live-20261004.json',
        'live_sha256': '9185504430499f87c44f7c6bb72e0d3bfb21c5230c199c0ac2e8e38f7739639d',
        'codes': {13, 15},
    },
}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def printed_result(text: str, valid: int, code: int) -> dict:
    rows = re.findall(r'^\s*(Winner|Runner up)\s*:?\s*(\S+)\s+(.+?)\s+(\d+)\s*$',
                      text, re.I | re.M)
    margin = re.search(r'MARGIN\s*:\s*(\d+)', text, re.I)
    if (len(rows) != 2 or [row[0].casefold() for row in rows] != ['winner', 'runner up']
            or margin is None):
        raise ValueError(f'Official result rows missing for {code}')
    winner, runner = rows
    win_votes, run_votes, margin_votes = int(winner[3]), int(runner[3]), int(margin[1])
    if not 0 <= run_votes < win_votes <= valid or win_votes - run_votes != margin_votes:
        raise ValueError(f'Official margin does not reconcile for {code}')
    return {'winner': winner[2].strip(), 'winner_party': winner[1].strip(),
            'winner_votes': win_votes, 'runner': runner[2].strip(),
            'runner_party': runner[1].strip(), 'runner_votes': run_votes,
            'margin': margin_votes}


def guarded_import_script() -> str:
    script = import_script(list(EDITIONS))
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
    with (root / 'tmp/election-display-gaps-20261004-0649.csv').open(encoding='utf-8', newline='') as handle:
        gaps = list(csv.DictReader(handle))
    staged_bodies = []
    audit = []
    for edition, config in EDITIONS.items():
        targets = {int(row['code']) for row in gaps if row['edition_id'] == edition
                   and row['issue'] == 'winner_hidden_with_candidate_votes'}
        if targets != config['codes'] or {row['extraction_sha256'] for row in gaps
                                         if row['edition_id'] == edition} != {config['live_sha256']}:
            raise ValueError('Live display audit differs for ' + edition)
        folder = root / 'application/storage/app/private/election-archive' / edition
        old_body = (root / 'tmp' / config['live_file']).read_bytes()
        if digest(old_body) != config['live_sha256']:
            raise ValueError('Verified live extraction differs for ' + edition)
        old = json.loads(old_body)
        revised = json.loads(old_body)
        manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
        source_file = edition + '-' + config['file_suffix'] + '.pdf'
        source_path = folder / source_file
        sources = [source for source in manifest['files'] if source['file'] == source_file]
        if (old['kind'] != 'ac' or old['year'] != 2005 or old['source_url'] != config['source_url']
                or manifest['url'] != config['source_url'] or old['source_file'] != source_file
                or old['source_sha256'] != config['source_sha256'] or len(sources) != 1
                or sources[0]['sha256'] != config['source_sha256'] or source_path.is_symlink()
                or digest(source_path.read_bytes()) != config['source_sha256']):
            raise ValueError('Official source identity or hash differs for ' + edition)
        summaries = read_summary_pages(source_path)
        if len(summaries) != len(old['records']) or set(summaries) != {row['code'] for row in old['records']}:
            raise ValueError('Official constituency summaries incomplete for ' + edition)
        results = []
        with fitz.open(source_path) as pdf:
            for record in revised['records']:
                code = record['code']
                if code not in targets:
                    continue
                summary = summaries[code]
                if (record['state_name'] != config['state'] or record['number_of_seats'] != 1
                        or record['status'] != 'needs_review' or record['error'] != PREVIOUS_NOTE
                        or record['source_warning_code'] != 'official_summary_turnout_only'
                        or not isinstance(record.get('original_extraction_warning'), str)
                        or record.get('summary_result') is not None
                        or record.get('winner') is not None or record.get('margin') is not None
                        or record['summary_page'] != summary['summary_page']
                        or record['summary_source_file'] != source_file
                        or record['summary_source_sha256'] != config['source_sha256']
                        or normalized(record['name']) != normalized(summary['name'])
                        or (record['electors'], record['votes_polled'],
                            record['summary_totals']['valid_candidate_votes'])
                        != (summary['electors'], summary['votes_polled'],
                            summary['valid_candidate_votes'])
                        or not 0 < summary['valid_candidate_votes'] <= summary['votes_polled'] <= summary['electors']):
                    raise ValueError('Live record and official summary differ for ' + str(code))
                result = printed_result(pdf[summary['summary_page'] - 1].get_text(sort=True),
                                        summary['valid_candidate_votes'], code)
                record['previous_review_note'] = record['error']
                record['error'] = NOTE
                record['summary_result'] = result
                results.append({'code': code, 'name': record['name'], 'summary_page': summary['summary_page'],
                                'winner': result['winner'], 'winner_party': result['winner_party'],
                                'winner_votes': result['winner_votes'], 'runner': result['runner'],
                                'runner_votes': result['runner_votes'], 'margin': result['margin'],
                                'candidate_rows_incomplete': sum(c['votes'] for c in record['candidates'])
                                != summary['valid_candidate_votes']})
        if {row['code'] for row in results} != targets:
            raise ValueError('Official target coverage differs for ' + edition)
        for before, after in zip(old['records'], revised['records'], strict=True):
            changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
            if before['code'] != after['code'] or changed != (
                    {'previous_review_note', 'error', 'summary_result'} if before['code'] in targets else set()):
                raise ValueError('Unrelated source evidence changed for ' + edition)
        new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
        staged_bodies.append((edition, config['live_sha256'], old_body, new_body))
        audit.append({'edition': edition, 'state': config['state'], 'source_url': config['source_url'],
                      'source_file': source_file, 'source_sha256': config['source_sha256'],
                      'previous_sha256': config['live_sha256'], 'new_sha256': digest(new_body),
                      'results': results})
    with tempfile.TemporaryDirectory(prefix='ac-2005-declared-', dir=root / 'exports') as directory:
        temp = Path(directory)
        staged = temp / 'archive'
        packages = temp / 'packages'
        packages.mkdir()
        inner = []
        for edition, previous_sha, old_body, new_body in staged_bodies:
            snapshot = f'election-archive/{edition}/extraction-{previous_sha}.json'
            revision = f'election-archive/{edition}/extraction.json'
            for relative, body in ((snapshot, old_body), (revision, new_body)):
                path = staged / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(body)
            for kind, relative in (('snapshot', snapshot), ('correction', revision)):
                bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
                path = packages / f'{kind}-{edition}.zip'
                package(staged, path, 'election-archive', bucket, 8, [relative],
                        previous_sha if kind == 'correction' else None,
                        snapshot if kind == 'correction' else None)
                inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as bundle:
            for path in inner:
                bundle.write(path, path.name)
            bundle.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n'
                                               for path in inner))
            bundle.writestr('ARCHIVES', ''.join(edition + '\n' for edition in EDITIONS))
            bundle.writestr('AUDIT.json', json.dumps({'scope': '30 source-printed 2005 AC declared results; '
                                                     'detailed candidate rows unchanged',
                                                     'editions': audit}, indent=2))
            bundle.writestr('IMPORT.sh', guarded_import_script())
        partial.replace(output)
    sha = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{sha}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': sha, 'results': sum(len(row['results']) for row in audit),
            'editions': [{'edition': row['edition'], 'previous_sha256': row['previous_sha256'],
                          'new_sha256': row['new_sha256']} for row in audit]}


if __name__ == '__main__':
    print(json.dumps(build()))
