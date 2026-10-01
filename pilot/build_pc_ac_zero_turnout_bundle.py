"""Package source-printed turnout for otherwise blank PC/AC summary records."""

import copy
import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

from build_assembly_summary_bundle import file_digest
from extract_assembly_summary_totals import read_summary_pages
from preserve_archive_json import package


NAME = 'pollmedia-pc-ac-zero-turnout-corrections-20261001-v3'
CURRENT_PACKAGES = ('pollmedia-ac-summary-corrections-20261001-v7.zip',
                    'pollmedia-ac-workbook-summary-corrections-20261001-v5.zip')
EDITION_IDS = (
    '6210f814ee5b46e924395875', 'dc469be7915c7a5a9e699aac',
    '47d0505498dc2d0ffce6c308', '22d3dca0090df0f6532ee43b',
    '25d92852e9975cf32d1b94bd', '1bec8e5043378d3d7ee107b2',
    'd6406685c07eb45be92daa3d', '775e12dc77eb9f634ba9a490',
    '8393265a724e9a7a3fa5abf4', '1ca25a475b1ef838be42b0b8',
    '3fdbf401aeb74266e09309ab', 'f4df4876a829786cd04cb280',
    '13651fccf222dbaabb514501', 'f8f3b9152832a818512cd0fb',
    '79ebd83ef86bed336cc3ef0f', 'ea63a136963eb905f001a70e',
    '156237297630a44f74e4d401', 'd34e828e1f0e22a96693d1c0',
    'c590e168b33b5fb8f213d092', 'ad5a2b4658b6047e9d0cd86b',
    'e1372f39c9e60519335a29f8', 'fb76cebe74b1c87526369724',
    '06dc8260d112f93ec51b9e06', '9cb8816f5dd2073708040572',
    'f3a3490e9bee0775959176ef', 'c7e2b7eb4781f4fee9dbeca3',
    'd6d8e8eaa48a3eb8251003d5', '0a8d70ea7e38075bea7a6253',
    'f0d9e36a60bcef19312cabc4', '60b51eb873eefae10d9f9aae',
)


def normalized(name: str) -> str:
    return re.sub(r'[^a-z0-9]', '', re.sub(r'\s*\((?:SC|ST)\)\s*$', '', name, flags=re.I).casefold())


def current_body(root: Path, edition: str) -> bytes:
    path = root / 'application/storage/app/private/election-archive' / edition / 'extraction.json'
    body = path.read_bytes()
    for filename in CURRENT_PACKAGES:
        outer_path = root / 'exports' / filename
        if file_digest(outer_path) != outer_path.with_suffix('.sha256').read_text().split()[0]:
            raise ValueError('An already-imported correction bundle has a different checksum')
        with zipfile.ZipFile(outer_path) as outer:
            member = f'correction-{edition}.zip'
            if member not in outer.namelist():
                continue
            with zipfile.ZipFile(io.BytesIO(outer.read(member))) as inner:
                manifest = json.loads(inner.read('manifest.json'))['files'][0]
                if (manifest['path'] != f'election-archive/{edition}/extraction.json'
                        or hashlib.sha256(body).hexdigest() != manifest['replaces_sha256']):
                    raise ValueError('Local archived extraction does not match the imported correction prior SHA')
                body = inner.read(manifest['path'])
                if hashlib.sha256(body).hexdigest() != manifest['sha256']:
                    raise ValueError('Imported correction payload checksum differs')
    return body


def revised_records(data: dict, summaries: dict, source: dict) -> tuple[dict, dict]:
    revised = copy.deepcopy(data)
    counts = {'recovered': 0, 'source_valid_exceeds_voters': 0, 'candidate_total_difference': 0,
              'elector_difference': 0, 'elector_conflicts_skipped': 0, 'other_unmatched': 0}
    for record in revised['records']:
        if record.get('votes_polled') not in (None, 0):
            continue
        summary = summaries.get(record.get('code'))
        if (summary is None or normalized(record.get('name') or '') != normalized(summary['name'])
                or record.get('status') != 'needs_review'
                or record.get('summary_totals') not in (None, {})
                or record.get('summary_page') not in (None, summary['summary_page'])):
            counts['other_unmatched'] += 1
            continue
        electors = record.get('electors')
        elector_difference = electors is not None and electors != summary['electors']
        if elector_difference:
            if (type(electors) is not int or electors <= 0
                    or abs(electors - summary['electors']) * 10000 > summary['electors'] * 5):
                counts['elector_conflicts_skipped'] += 1
                continue
        if summary['votes_polled'] <= 0 or summary['votes_polled'] > summary['electors']:
            raise ValueError('Official summary voter total is out of range')
        original_error = record.get('error') or ''
        record['original_extraction_warning'] = original_error
        note = 'Official constituency summary supplies electors and voters; detailed candidate rows remain under review.'
        if elector_difference:
            note += (f" Detailed electors: {electors:,}; official summary electors: "
                     f"{summary['electors']:,}. Turnout uses the summary denominator.")
            record['source_discrepancy'] = {'field': 'electors', 'detail_value': electors,
                                            'summary_value': summary['electors']}
            counts['elector_difference'] += 1
        if summary['valid_candidate_votes'] > summary['votes_polled']:
            note += (f" The report prints {summary['valid_candidate_votes']:,} valid votes but "
                     f"{summary['votes_polled']:,} voters; turnout uses the printed voter total.")
            counts['source_valid_exceeds_voters'] += 1
        candidate_total = record.get('valid_candidate_votes')
        if candidate_total is not None and candidate_total != summary['valid_candidate_votes']:
            note += (f" Detailed valid votes: {candidate_total:,}; official summary valid votes: "
                     f"{summary['valid_candidate_votes']:,}.")
            counts['candidate_total_difference'] += 1
        record['error'] = note
        record['source_warning_code'] = 'official_summary_turnout_only'
        record['electors'] = summary['electors'] if electors is None else electors
        record['votes_polled'] = summary['votes_polled']
        record['summary_totals'] = {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
        record['summary_page'] = summary['summary_page']
        record['summary_source_file'] = source['file']
        record['summary_source_sha256'] = source['sha256']
        counts['recovered'] += 1
    return revised, counts


def import_script(editions: list[str]) -> str:
    return '''#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
exec 9> .import.lock
flock -n 9 || { echo 'Election JSON import already running' >&2; exit 1; }
sha256sum -c SHA256SUMS
artisan=/home/pollmedia/app/application/artisan
test -f "$artisan"
check_disk() {
    available=$(df -Pk /home/pollmedia/app | awk 'NR==2 {print $4}')
    test "$available" -ge 10485760 || { echo 'Less than 10 GiB free on server' >&2; exit 1; }
}
check_disk
while IFS= read -r archive; do
    test ! -f "/home/pollmedia/app/application/storage/app/private/election-archive/$archive/extraction.json" || { echo "Local extraction would shadow database revision: $archive" >&2; exit 1; }
done < ARCHIVES
ELECTION_ARCHIVES="$(paste -sd, ARCHIVES)" php8.4 "$artisan" tinker --execute 'if (\\Illuminate\\Support\\Facades\\DB::table("historical_election_reviews")->whereIn("archive", explode(",", getenv("ELECTION_ARCHIVES")))->exists()) { throw new \\RuntimeException("A revised archive has review overlays; merge them before importing."); }'
for file in snapshot-*.zip; do
    check_disk
    checksum=$(sha256sum "$file" | awk '{print $1}')
    php8.4 "$artisan" archive:import-json "$PWD/$file" "--sha256=$checksum" --check
    php8.4 "$artisan" archive:import-json "$PWD/$file" "--sha256=$checksum"
done
for file in correction-*.zip; do
    check_disk
    checksum=$(sha256sum "$file" | awk '{print $1}')
    php8.4 "$artisan" archive:import-json "$PWD/$file" "--sha256=$checksum" --check --allow-revision
    php8.4 "$artisan" archive:import-json "$PWD/$file" "--sha256=$checksum" --allow-revision
done
check_disk
php8.4 "$artisan" archive:index-constituencies --check
php8.4 "$artisan" archive:index-constituencies
check_disk
'''


def build(root: Path) -> dict:
    exports = root / 'exports'
    bundle = exports / (NAME + '.zip')
    if bundle.exists() or bundle.with_suffix('.sha256').exists():
        raise FileExistsError(bundle)
    details = []
    with tempfile.TemporaryDirectory(prefix='pc-ac-zero-', dir=exports) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        for edition in EDITION_IDS:
            folder = root / 'application/storage/app/private/election-archive' / edition
            old_body = current_body(root, edition)
            data = json.loads(old_body)
            manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
            if manifest.get('url') != data.get('source_url') or data.get('kind') != 'ac':
                raise ValueError('Official edition identity differs: ' + edition)
            source_file = data.get('source_file')
            matching = [item for item in manifest['files'] if item.get('file') == source_file]
            source_path = folder / source_file
            if (len(matching) != 1 or matching[0]['sha256'] != data.get('source_sha256')
                    or not source_path.is_file() or source_path.is_symlink()
                    or file_digest(source_path) != matching[0]['sha256']):
                raise ValueError('Official PDF checksum differs: ' + edition)
            summaries = read_summary_pages(source_path, allow_vote_discrepancy=True)
            revised, counts = revised_records(data, summaries, {'file': source_file, 'sha256': matching[0]['sha256']})
            if counts['recovered'] == 0:
                continue
            if len(revised['records']) != len(data['records']):
                raise ValueError('Constituency coverage changed')
            for before, after in zip(data['records'], revised['records']):
                if (before['code'] != after['code'] or before.get('candidates') != after.get('candidates')
                        or before.get('status') != after.get('status')
                        or before.get('valid_candidate_votes') != after.get('valid_candidate_votes')
                        or (before.get('electors') is not None and before['electors'] != after['electors'])):
                    raise ValueError('A nonzero source field, candidate, status or identity changed')
            new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
            old_sha = hashlib.sha256(old_body).hexdigest()
            snapshot = f'election-archive/{edition}/extraction-{old_sha}.json'
            revision = f'election-archive/{edition}/extraction.json'
            for relative, body in ((snapshot, old_body), (revision, new_body)):
                target = staged / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(body)
            details.append({'edition': edition, 'year': data['year'], 'state': data['records'][0].get('state_name'),
                            'source_url': data['source_url'], 'source_file': source_file,
                            'source_sha256': matching[0]['sha256'], 'previous_sha256': old_sha,
                            'new_sha256': hashlib.sha256(new_body).hexdigest(), 'snapshot': snapshot,
                            'revision': revision, **counts})
        if not details:
            raise ValueError('No zero-turnout values could be source-matched')
        package_files = []
        for kind in ('snapshot', 'correction'):
            for detail in details:
                edition = detail['edition']
                relative = detail['snapshot'] if kind == 'snapshot' else detail['revision']
                bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
                output = packages / f'{kind}-{edition}.zip'
                package(staged, output, 'election-archive', bucket, 8, [relative],
                        detail['previous_sha256'] if kind == 'correction' else None,
                        detail['snapshot'] if kind == 'correction' else None)
                package_files.append(output)
        partial = bundle.with_suffix('.zip.partial')
        if partial.exists():
            raise FileExistsError(partial)
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for item in package_files:
                zipped.write(item, item.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{file_digest(item)}  {item.name}\n' for item in package_files))
            zipped.writestr('ARCHIVES', ''.join(detail['edition'] + '\n' for detail in details))
            zipped.writestr('AUDIT.json', json.dumps({'scope': 'Source-printed PC/AC zero turnout only',
                                                    'editions': details}, ensure_ascii=False, indent=2))
            zipped.writestr('IMPORT.sh', import_script([detail['edition'] for detail in details]))
        partial.replace(bundle)
    checksum = file_digest(bundle)
    bundle.with_suffix('.sha256').write_bytes((checksum + '  ' + bundle.name + '\n').encode('ascii'))
    return {'bundle': str(bundle), 'sha256': checksum, 'editions': len(details),
            'recovered': sum(item['recovered'] for item in details), 'bytes': bundle.stat().st_size,
            'elector_difference': sum(item['elector_difference'] for item in details),
            'source_valid_exceeds_voters': sum(item['source_valid_exceeds_voters'] for item in details),
            'candidate_total_difference': sum(item['candidate_total_difference'] for item in details)}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
