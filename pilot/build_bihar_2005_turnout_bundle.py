"""Restore only Bihar 2005 turnout from the two preserved official summaries."""

import copy
import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

from extract_assembly_summary_totals import read_summary_pages
from preserve_archive_json import package


EDITION = 'faf93ea0918e67d6bc68067a'
SOURCE_URL = 'https://old.eci.gov.in/files/file/3901-bihar-2005/'
NAME = 'pollmedia-bihar-2005-turnout-correction-20261001'
PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
RECONCILED = ('Official round-specific constituency summary confirms electors, voters and valid votes; '
              'candidate extraction warnings remain under review.')
ROUNDS = {'2005-feb': 100000, '2005-oct': 200000}


def sha256(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def file_sha256(path: Path) -> str:
    with path.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest()


def normalized(name: str) -> str:
    return re.sub(r'[^a-z0-9]', '', name.casefold())


def match_rounds(folder: Path, data: dict) -> dict:
    """Identify each PDF by all 243 seat names and valid-vote totals, never filename order."""
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if manifest.get('url') != SOURCE_URL or data.get('source_url') != SOURCE_URL:
        raise ValueError('Bihar 2005 official URL differs')
    files = [item for item in manifest.get('files', []) if str(item.get('file', '')).lower().endswith('.pdf')]
    if len(files) != 2 or len({item['file'] for item in files}) != 2:
        raise ValueError('Expected exactly two preserved Bihar 2005 official PDFs')
    if not any(item['file'] == data.get('source_file') and item['sha256'] == data.get('source_sha256') for item in files):
        raise ValueError('Archived detail PDF identity differs')
    records = {round_name: [record for record in data['records'] if record.get('election_round') == round_name]
               for round_name in ROUNDS}
    if len(data['records']) != 486 or any(len(rows) != 243 for rows in records.values()):
        raise ValueError('Bihar 2005 round coverage differs')

    matched = {}
    for item in files:
        source = folder / item['file']
        if not source.is_file() or source.is_symlink() or file_sha256(source) != item['sha256']:
            raise ValueError('An official Bihar PDF is unavailable or has a different checksum')
        summaries = read_summary_pages(source)
        if set(summaries) != set(range(1, 244)):
            raise ValueError('Official Bihar summary seat coverage differs')
        possible = []
        for round_name, rows in records.items():
            if all(normalized(row['name'].removesuffix(' / ' + round_name)) == normalized(summaries[row['official_ac_code']]['name'])
                   and row.get('valid_candidate_votes') == summaries[row['official_ac_code']]['valid_candidate_votes']
                   for row in rows):
                possible.append(round_name)
        if len(possible) != 1 or possible[0] in matched:
            raise ValueError('Official Bihar PDF cannot be matched to exactly one election round')
        matched[possible[0]] = {'summaries': summaries, 'file': item['file'], 'sha256': item['sha256']}
    if set(matched) != set(ROUNDS):
        raise ValueError('Both official Bihar election rounds were not matched')
    return matched


def revised_records(data: dict, matched: dict) -> tuple[dict, int]:
    revised = copy.deepcopy(data)
    if revised.get('kind') != 'ac' or revised.get('year') != 2005:
        raise ValueError('This correction is only for Bihar AC 2005')
    count = 0
    for record in revised['records']:
        round_name = record.get('election_round')
        if round_name not in ROUNDS or record.get('source_document') != round_name + '.pdf':
            raise ValueError('Bihar record round identity differs')
        code = record.get('official_ac_code')
        if type(code) is not int or not 1 <= code <= 243 or record.get('code') != ROUNDS[round_name] + code:
            raise ValueError('Bihar round-specific constituency code differs')
        summary = matched[round_name]['summaries'][code]
        if (normalized(record.get('name', '').removesuffix(' / ' + round_name)) != normalized(summary['name'])
                or record.get('valid_candidate_votes') != summary['valid_candidate_votes']
                or record.get('electors') is not None or record.get('votes_polled') is not None
                or record.get('summary_page') is not None or record.get('summary_totals') not in (None, {})
                or record.get('status') != 'needs_review' or type(record.get('detail_page')) is not int
                or record['detail_page'] <= 0 or not (record.get('error') or '').startswith(PENDING)):
            raise ValueError('Bihar source record cannot be safely revised: ' + str(record.get('code')))
        original_error = record['error']
        record['original_extraction_warning'] = original_error
        record['error'] = RECONCILED + original_error[len(PENDING):]
        record['source_warning_code'] = 'round_specific_summary'
        record['electors'] = summary['electors']
        record['votes_polled'] = summary['votes_polled']
        record['summary_totals'] = {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
        record['summary_page'] = summary['summary_page']
        record['summary_source_file'] = matched[round_name]['file']
        record['summary_source_sha256'] = matched[round_name]['sha256']
        count += 1
    if count != 486:
        raise ValueError('Expected 486 Bihar round-specific seats')
    return revised, count


def build(root: Path) -> dict:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    original_path = folder / 'extraction.json'
    original = original_path.read_bytes()
    data = json.loads(original)
    matched = match_rounds(folder, data)
    revised, count = revised_records(data, matched)
    for before, after in zip(data['records'], revised['records']):
        if (before['code'] != after['code'] or before.get('candidates') != after.get('candidates')
                or before.get('status') != after.get('status') or before.get('detail_totals') != after.get('detail_totals')):
            raise ValueError('Candidate, identity, status or detailed totals changed')
    old_sha = sha256(original)
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    exports = root / 'exports'
    exports.mkdir(exist_ok=True)
    bundle = exports / (NAME + '.zip')
    if bundle.exists() or bundle.with_suffix('.sha256').exists():
        raise FileExistsError(bundle)
    with tempfile.TemporaryDirectory(prefix='bihar-2005-', dir=exports) as temporary:
        staging = Path(temporary)
        archive = staging / 'archive'
        packages = staging / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{old_sha}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        for relative, body in ((snapshot, original), (revision, new_body)):
            target = archive / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
        inner = []
        for prefix, relative in (('snapshot', snapshot), ('correction', revision)):
            output = packages / f'{prefix}-{EDITION}.zip'
            bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
            package(archive, output, 'election-archive', bucket, 8, [relative],
                    old_sha if prefix == 'correction' else None,
                    snapshot if prefix == 'correction' else None)
            inner.append(output)
        checksums = ''.join(f'{file_sha256(item)}  {item.name}\n' for item in inner)
        script = '''#!/usr/bin/env bash
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
test ! -f /home/pollmedia/app/application/storage/app/private/election-archive/''' + EDITION + '''/extraction.json || { echo 'Local extraction would shadow database revision' >&2; exit 1; }
ELECTION_ARCHIVE=''' + EDITION + ''' php8.4 "$artisan" tinker --execute 'if (\\Illuminate\\Support\\Facades\\DB::table("historical_election_reviews")->where("archive", getenv("ELECTION_ARCHIVE"))->exists()) { throw new \\RuntimeException("The archive has review overlays; merge them before importing."); }'
for file in snapshot-*.zip correction-*.zip; do
    check_disk
    checksum=$(sha256sum "$file" | awk '{print $1}')
    if [[ "$file" == correction-* ]]; then
        php8.4 "$artisan" archive:import-json "$PWD/$file" "--sha256=$checksum" --check --allow-revision
        php8.4 "$artisan" archive:import-json "$PWD/$file" "--sha256=$checksum" --allow-revision
    else
        php8.4 "$artisan" archive:import-json "$PWD/$file" "--sha256=$checksum" --check
        php8.4 "$artisan" archive:import-json "$PWD/$file" "--sha256=$checksum"
    fi
done
check_disk
php8.4 "$artisan" archive:index-constituencies --check
php8.4 "$artisan" archive:index-constituencies
check_disk
'''
        partial = bundle.with_suffix('.zip.partial')
        if partial.exists():
            raise FileExistsError(partial)
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for item in inner:
                zipped.write(item, item.name)
            zipped.writestr('SHA256SUMS', checksums)
            zipped.writestr('ARCHIVES', EDITION + '\n')
            zipped.writestr('AUDIT.json', json.dumps({'scope': 'Bihar AC 2005 source-summary turnout only',
                                                    'edition': EDITION, 'old_sha256': old_sha,
                                                    'new_sha256': sha256(new_body), 'records': count,
                                                    'round_sources': {round_name: {k: v for k, v in source.items() if k != 'summaries'}
                                                                      for round_name, source in matched.items()}}, indent=2))
            zipped.writestr('IMPORT.sh', script)
        partial.replace(bundle)
    if original_path.read_bytes() != original:
        raise RuntimeError('Local extraction changed during packaging')
    checksum = file_sha256(bundle)
    bundle.with_suffix('.sha256').write_bytes((checksum + '  ' + bundle.name + '\n').encode('ascii'))
    return {'bundle': str(bundle), 'sha256': checksum, 'records': count, 'bytes': bundle.stat().st_size}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
