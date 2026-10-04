"""Rebase three official 2012 Assembly declarations on observed live revisions."""

import copy
from dataclasses import dataclass
import hashlib
import io
import json
from pathlib import Path
import tempfile
import zipfile

from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package
from rebase_election_revision import rebase


ROOT = Path(__file__).resolve().parents[1]
TURNOUT_WAVE = 'pollmedia-pc-ac-zero-turnout-corrections-20261001-v3'
TURNOUT_WAVE_SHA = '5449afc95c14440ecb3865d7ffe9717886ce8576f6f2a8ad5f0ce5667b86e8d7'
RESIDUAL_WAVE = 'pollmedia-ac-residual-turnout-corrections-20261001-v4'
RESIDUAL_WAVE_SHA = 'ad1c4fcc0c246805bc44974882776d2503677faad549a0b5857f402bf4dd69c0'


@dataclass(frozen=True)
class Edition:
    state: str
    edition: str
    seats: int
    source_sha: str
    wave: str
    wave_sha: str
    inner: str
    old_sha: str
    proposed_sha: str
    live_wave: str
    live_wave_sha: str
    live_sha: str
    overlaps: tuple[int, ...]
    output: str


EDITIONS = (
    Edition('Uttarakhand', 'ad5a2b4658b6047e9d0cd86b', 70,
            '82e9509da1929c0dd8389761f842e0abcc2476e048439a122abed1aad8f8c5ea',
            'pollmedia-election-corrections-20261003-wave12',
            '95904cd9cdde7da9c7bc586a16de1fea8d8d3cd0d9f0b50438cc9807445a80f6',
            'pollmedia-ac-2012-uttarakhand-declared-results-20261003',
            'f4d7ec63dfc8a6c87e3aa72ffc44848248e94b94005422e673ab50359b14979e',
            '92e5ef46e78c6a405275cd6a349dfb12d178624a2a878f443602fca3a9a7a4e7',
            TURNOUT_WAVE, TURNOUT_WAVE_SHA,
            '09982928b0e09c2a4f0d180ba227600837b7b545052175f6611f9f0050baf56c',
            (4, 6, 67), 'pollmedia-ac-2012-uttarakhand-live-rebased-results-20261004'),
    Edition('Himachal Pradesh', '13651fccf222dbaabb514501', 68,
            '7440db9f822279cc2af0b6cb5c224bddf273c3bb1dcf35cc47c3a0b6743aece4',
            'pollmedia-election-corrections-20261003-wave13',
            '209e3f2e01e624946c440d70fa047bf0aa09f36465848cf3cba28da4fa288d4d',
            'pollmedia-ac-2012-himachal-pradesh-declared-results-20261003',
            '2b5c9ddd0b41e1df6e25255571d90a34121acc8c12a9254fe72e9ec42a6a118b',
            '30b88ca1c4864c7d7df76c0cc8faab323f27f335b3f13bffb509a3f723fb2772',
            TURNOUT_WAVE, TURNOUT_WAVE_SHA,
            '2d123b8f20a465762bbb13a8d9d70931e1670e678aabddfe5c5c1a4c10b98360',
            (36, 37, 43, 57, 61, 62),
            'pollmedia-ac-2012-himachal-live-rebased-results-20261004'),
    Edition('Gujarat', '503135d3e838d38c93d3bce7', 182,
            '5c01eb6fdc9a01f445526932c0f5f4e4e3743b52fcf30f1b0854749c25fd4ee3',
            'pollmedia-election-corrections-20261004-wave24-v2',
            'bded4cdceafa3ec7bf08effe5b7be67c0e52d15d14591bfff74292449a038f38',
            'pollmedia-ac-gujarat-2012-summary-results-20261003-v5',
            '587b4dceb38eb1edba9c412092fc30a128222fca3405ba0e086f5f3e40e42a93',
            'f90a406c82b22a3ed99a7b29137036833c05908b4aae649a1e098552eb714c05',
            RESIDUAL_WAVE, RESIDUAL_WAVE_SHA,
            '99361d3365a69e093c93e9ce7db04a50e045da4c5dbd2046f97407184058e70e',
            (4, 15, 20, 31, 32, 38, 40, 42, 43, 44, 52, 65, 66, 68, 78, 79, 83,
             91, 92, 99, 114, 115, 120, 128, 129, 137, 140, 147, 148, 157,
             173, 176, 177),
            'pollmedia-ac-2012-gujarat-live-rebased-results-20261004'),
)


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def checked_zip(root: Path, name: str, expected: str) -> zipfile.ZipFile:
    path = root / 'exports' / (name + '.zip')
    if (digest(path.read_bytes()) != expected
            or path.with_suffix('.sha256').read_bytes() !=
            f'{expected}  {path.name}\n'.encode('ascii')):
        raise ValueError('Sealed election package differs: ' + name)
    return zipfile.ZipFile(path)


def extract(bundle: zipfile.ZipFile, edition: str, kind: str) -> bytes:
    with zipfile.ZipFile(io.BytesIO(bundle.read(f'{kind}-{edition}.zip'))) as inner:
        paths = [path for path in inner.namelist()
                 if path.startswith(f'election-archive/{edition}/') and path.endswith('.json')]
        if len(paths) != 1:
            raise ValueError('Expected one source extraction')
        body = inner.read(paths[0])
        manifest = json.loads(inner.read('manifest.json'))['files'][0]
        if manifest['path'] != paths[0] or manifest['sha256'] != digest(body):
            raise ValueError('Archived election extraction checksum differs')
        return body


def source_bytes(root: Path, config: Edition) -> tuple[bytes, bytes, bytes]:
    with checked_zip(root, config.wave, config.wave_sha) as wave:
        inner_body = wave.read(config.inner + '.zip')
        expected_inner = wave.read(config.inner + '.sha256').decode('ascii').split()[0]
        if digest(inner_body) != expected_inner:
            raise ValueError('Official-result bundle checksum differs')
        with zipfile.ZipFile(io.BytesIO(inner_body)) as bundle:
            old = extract(bundle, config.edition, 'snapshot')
            proposed = extract(bundle, config.edition, 'correction')
    with checked_zip(root, config.live_wave, config.live_wave_sha) as bundle:
        live = extract(bundle, config.edition, 'correction')
    if tuple(map(digest, (old, proposed, live))) != (
            config.old_sha, config.proposed_sha, config.live_sha):
        raise ValueError('Election revision chain differs: ' + config.state)
    original = json.loads(old)
    pdf = root / 'application/storage/app/private/election-archive' / config.edition / original['source_file']
    if (original['kind'], original['year'], original['source_sha256']) != (
            'ac', 2012, config.source_sha) or digest(pdf.read_bytes()) != config.source_sha:
        raise ValueError('Official report differs: ' + config.state)
    return old, proposed, live


def revised_edition(config: Edition, root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    old_body, proposed_body, live_body = source_bytes(root, config)
    old, proposed, live = map(json.loads, (old_body, proposed_body, live_body))
    before = {row['code']: row for row in old['records']}
    current = {row['code']: row for row in live['records']}
    if len(before) != config.seats or set(before) != set(current):
        raise ValueError('Constituency coverage differs: ' + config.state)
    overlap_codes = set(config.overlaps)
    if config.state == 'Gujarat':
        prior_notes = {}
        for row in proposed['records']:
            code = row['code']
            if code not in overlap_codes:
                continue
            earlier, now = before[code], current[code]
            summary = row['summary_totals']
            detail = now['turnout_totals']
            if (now['source_warning_code'] != 'official_turnout_from_residual_source'
                    or now['votes_polled'] != detail['votes_polled']
                    or now['votes_polled'] != summary['valid_candidate_votes']
                    or not summary['valid_candidate_votes'] < summary['votes_polled'] <= summary['electors']
                    or detail['general_votes'] + detail['postal_votes'] != detail['votes_polled']
                    or now['turnout_source_sha256'] != config.source_sha
                    or now['original_extraction_warning'] != earlier['error']
                    or not row['summary_result'] or row['summary_source_sha256'] != config.source_sha
                    or (row['electors'] != summary['electors'] and
                        row.get('source_discrepancy') != {
                            'field': 'electors', 'detail_value': row['electors'],
                            'summary_value': summary['electors']})):
                raise ValueError(f'Unreviewed Gujarat summary/detail overlap: {code}')
            overlap = {'error', 'source_warning_code', 'votes_polled'}
            if now.get('electors') != earlier.get('electors') and row.get('electors') != now.get('electors'):
                overlap.add('electors')
            prior_notes[code] = now['error']
            for field in overlap:
                if field in earlier:
                    row[field] = earlier[field]
                else:
                    row.pop(field, None)
        adjusted = json.dumps(proposed, ensure_ascii=False, indent=2).encode('utf-8')
        merged_body, changes = rebase(old_body, adjusted, live_body)
        merged = json.loads(merged_body)
        for row in merged['records']:
            code = row['code']
            if code not in overlap_codes:
                continue
            declared = next(item for item in json.loads(proposed_body)['records'] if item['code'] == code)
            detail = current[code]['turnout_totals']
            row['votes_polled'] = declared['votes_polled']
            row['electors'] = declared['electors']
            row['source_warning_code'] = declared['source_warning_code']
            row['previous_turnout_review_note'] = prior_notes[code]
            row['turnout_detail_discrepancy'] = {
                'field': 'votes_polled', 'detail_page': current[code]['turnout_source_page'],
                'detail_general_plus_postal': detail['votes_polled'],
                'official_summary_page': declared['summary_page'],
                'official_summary_total_voters': declared['summary_totals']['votes_polled'],
                'official_summary_valid_candidate_votes':
                    declared['summary_totals']['valid_candidate_votes'],
            }
            row['error'] = (declared['error'] + ' The earlier detail-page general-plus-postal '
                            'figure equals valid candidate votes, not the official summary total '
                            'voters; turnout uses the summary. Review both pages in the linked report.')
        if len(prior_notes) != 33:
            raise ValueError('Gujarat overlap count differs')
    else:
        for row in proposed['records']:
            code = row['code']
            if code not in overlap_codes:
                continue
            earlier, now = before[code], current[code]
            if (now['original_extraction_warning'] != earlier['error']
                    or now['votes_polled'] != row['votes_polled']
                    or now['summary_totals'] != row['summary_totals']
                    or now['summary_source_sha256'] != config.source_sha
                    or not row['error'].startswith(earlier['error'])
                    or not row['summary_result']):
                raise ValueError(f'Unreviewed {config.state} turnout/result overlap: {code}')
            row['error'] = earlier['error']
        adjusted = json.dumps(proposed, ensure_ascii=False, indent=2).encode('utf-8')
        merged_body, changes = rebase(old_body, adjusted, live_body)
        merged = json.loads(merged_body)
        original_proposed = {row['code']: row for row in json.loads(proposed_body)['records']}
        for row in merged['records']:
            if row['code'] in overlap_codes:
                code = row['code']
                row['error'] += original_proposed[code]['error'][len(before[code]['error']):]
    revised = json.dumps(merged, ensure_ascii=False, indent=2).encode('utf-8')
    after = {row['code']: row for row in merged['records']}
    if len(after) != config.seats or any(
            after[code]['candidates'] != prior['candidates']
            or after[code].get('original_extraction_warning') != prior.get('original_extraction_warning')
            or not after[code].get('summary_result')
            for code, prior in current.items()):
        raise ValueError('Live candidates, warning, or declared result changed')
    return live_body, revised, {
        'scope': f'{config.state} 2012 official declarations rebased on observed live revision',
        'edition': config.edition, 'year': 2012,
        'source_url': old['source_url'], 'source_file': old['source_file'],
        'source_sha256': config.source_sha,
        'previous_sha256': config.live_sha, 'new_sha256': digest(revised),
        'result_count': config.seats, 'overlap_codes': list(config.overlaps),
        'rebase_changes': changes,
    }


def build(config: Edition, root: Path = ROOT) -> dict:
    output = root / 'exports' / (config.output + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, revised, audit = revised_edition(config, root)
    with tempfile.TemporaryDirectory(prefix='ac-2012-live-rebase-', dir=output.parent) as temporary:
        stage = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{config.edition}/extraction-{config.live_sha}.json'
        correction = f'election-archive/{config.edition}/extraction.json'
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', correction, revised)):
            target = stage / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            package(stage, packages / f'{kind}-{config.edition}.zip', 'election-archive',
                    bucket, 8, [relative], config.live_sha if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
        inner = sorted(packages.glob('*.zip'))
        with zipfile.ZipFile(output, 'w', compression=zipfile.ZIP_STORED) as bundle:
            for path in inner:
                bundle.write(path, path.name)
            bundle.writestr('SHA256SUMS', ''.join(
                f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            bundle.writestr('ARCHIVES', config.edition + '\n')
            bundle.writestr('AUDIT.json', json.dumps(audit, indent=2))
            bundle.writestr('IMPORT.sh', import_script([config.edition]))
    checksum = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(
        f'{checksum}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': checksum,
            'previous_sha256': config.live_sha, 'new_sha256': audit['new_sha256']}


if __name__ == '__main__':
    for edition in EDITIONS:
        print(json.dumps(build(edition)))
