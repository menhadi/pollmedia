"""Restore four single-seat declarations whose detailed 1951 rows ran together."""

import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

import fitz

from build_ac_bombay_1951_four_single_seat_results import norm, summary_result
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


ROOT = Path(__file__).resolve().parents[1]
NAME = 'pollmedia-ac-1951-sourastra-mysore-four-summary-results-20261004'
SOURCES = {
    'affdd40634235082132e418b': {
        'before': '2fbcdd75a58779b555de97d6eba8d5747672612234a5aed82248249a010497d3',
        'pdf_sha256': '8b18ac69e999ac28891b23156b5f5874345a06550fe7adcbfaa617e46fd6ee8c',
        'url': 'https://old.eci.gov.in/files/file/4095-sourastra-1951/',
        'file': 'affdd40634235082132e418b-9702.pdf',
        'record_count': 55,
        # code: (PDF page, electors, polled, winner votes, runner votes, margin)
        'seats': {28: (38, 30115, 14206, 7435, 4850, 2585),
                  36: (46, 35584, 12699, 8057, 3770, 4287),
                  43: (53, 32544, 13089, 6164, 5913, 251)},
    },
    'a68e5ff94ea8b19adbf5378b': {
        'before': 'd3b7996c1243fec9a81a3187a82701f103cc720dd3ce4008bea7e487a458c165',
        'pdf_sha256': '42d1c87f6bc727ef313a852e35cba1fc5384857ca5655dd321425d806bfbe864',
        'url': 'https://old.eci.gov.in/files/file/4098-mysore-1951/',
        'file': 'a68e5ff94ea8b19adbf5378b-9708.pdf',
        'record_count': 80,
        'seats': {12: (23, 42169, 24256, 14863, 5864, 8999)},
    },
}


def sha(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(edition: str, root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    config = SOURCES[edition]
    folder = root / 'application/storage/app/private/election-archive' / edition
    before_body = (folder / 'extraction.json').read_bytes()
    before = json.loads(before_body)
    manifest = json.loads((folder / 'manifest.json').read_bytes())
    source = folder / config['file']
    if (sha(before_body) != config['before'] or before['kind'] != 'ac' or before['year'] != 1951
            or before['source_url'] != config['url'] or before['source_file'] != config['file']
            or before['source_sha256'] != config['pdf_sha256'] or manifest['url'] != config['url']
            or len(before['records']) != config['record_count']
            or len([row for row in manifest['files'] if row['file'] == config['file']
                    and row['sha256'] == config['pdf_sha256']]) != 1
            or sha(source.read_bytes()) != config['pdf_sha256']):
        raise ValueError(f'{edition} official source identity differs')
    after = json.loads(before_body)
    by_code = {record['code']: record for record in after['records']}
    if len(by_code) != len(after['records']):
        raise ValueError(f'{edition} duplicate constituency codes')
    with fitz.open(source) as pdf:
        for code, (page, electors, polled, winner_votes, runner_votes, margin) in config['seats'].items():
            record = by_code[code]
            name, totals, result = summary_result(pdf[page - 1].get_text(sort=True), code)
            if (norm(name) != norm(record['name']) or record['number_of_seats'] != 1
                    or record['status'] != 'needs_review' or not record.get('error')
                    or record.get('summary_page') is not None or record.get('winner') is not None
                    or record.get('margin') is not None or record['detail_page'] <= page
                    or totals != {'electors': electors, 'votes_polled': polled,
                                  'valid_candidate_votes': polled}
                    or (result['winner_votes'], result['runner_votes'], result['margin'])
                    != (winner_votes, runner_votes, margin)
                    or (record['electors'], record['votes_polled'], record['valid_candidate_votes'])
                    == (electors, polled, polled)):
                raise ValueError(f'{edition} official row or prior extraction differs: {code}')
            for key in ('winner', 'runner'):
                matches = [candidate for candidate in record['candidates']
                           if norm(candidate['candidate_name']) == norm(result[key])
                           and candidate['party_at_election'] == result[key + '_party']
                           and candidate['votes'] == result[key + '_votes']]
                if len(matches) != 1:
                    raise ValueError(f'{edition} declared {key} is not uniquely confirmed: {code}')
            record['original_extracted_totals'] = {key: record[key] for key in
                                                   ('electors', 'votes_polled', 'valid_candidate_votes')}
            record['original_extraction_warning'] = record['error']
            record['electors'] = electors
            record['votes_polled'] = polled
            record['valid_candidate_votes'] = polled
            record['error'] = ('Official summary gives turnout, winner and margin. Detailed candidate rows run '
                               'into adjacent tables and remain under review; check the linked report.')
            record['source_warning_code'] = 'summary_only_turnout'
            record['summary_source_file'] = config['file']
            record['summary_source_sha256'] = config['pdf_sha256']
            record['summary_page'] = page
            record['summary_totals'] = totals
            record['summary_result'] = result
    changed_fields = {'original_extracted_totals', 'original_extraction_warning', 'electors', 'votes_polled',
                      'valid_candidate_votes', 'error', 'source_warning_code', 'summary_source_file',
                      'summary_source_sha256', 'summary_page', 'summary_totals', 'summary_result'}
    for old, new in zip(before['records'], after['records']):
        changed = {key for key in set(old) | set(new) if old.get(key) != new.get(key)}
        if old['code'] != new['code'] or changed != (changed_fields if old['code'] in config['seats'] else set()):
            raise ValueError(f'{edition} unrelated extraction changed: {old["code"]}')
    after_body = json.dumps(after, ensure_ascii=False, indent=2).encode('utf-8')
    audit = {'edition': edition, 'codes': sorted(config['seats']), 'source_url': config['url'],
             'source_file': config['file'], 'source_sha256': config['pdf_sha256'],
             'previous_sha256': sha(before_body), 'new_sha256': sha(after_body)}
    return before_body, after_body, audit


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    revisions = {edition: revised_edition(edition, root) for edition in SOURCES}
    with tempfile.TemporaryDirectory(prefix='ac-1951-summary-', dir=output.parent) as temporary:
        staging = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        inner = []
        for edition, (before, after, audit) in revisions.items():
            snapshot = f'election-archive/{edition}/extraction-{audit["previous_sha256"]}.json'
            revision = f'election-archive/{edition}/extraction.json'
            for kind, relative, body in (('snapshot', snapshot, before), ('correction', revision, after)):
                target = staging / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(body)
                bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
                path = packages / f'{kind}-{edition}.zip'
                package(staging, path, 'election-archive', bucket, 8, [relative],
                        audit['previous_sha256'] if kind == 'correction' else None,
                        snapshot if kind == 'correction' else None)
                inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for path in inner:
                zipped.write(path, path.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{sha(path.read_bytes())}  {path.name}\n' for path in inner))
            zipped.writestr('ARCHIVES', ''.join(edition + '\n' for edition in revisions))
            zipped.writestr('AUDIT.json', json.dumps({'scope': '1951 Sourastra and Mysore four official single-seat summaries',
                                                     'editions': [revision[2] for revision in revisions.values()]}, indent=2))
            zipped.writestr('IMPORT.sh', import_script(list(revisions)))
        partial.replace(output)
    with output.with_suffix('.sha256').open('w', encoding='ascii', newline='\n') as checksum:
        checksum.write(f'{sha(output.read_bytes())}  {output.name}\n')
    return {'bundle': str(output), 'sha256': sha(output.read_bytes()),
            'editions': [revision[2] for revision in revisions.values()]}


if __name__ == '__main__':
    print(json.dumps(build()))
