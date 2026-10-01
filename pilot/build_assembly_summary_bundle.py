"""Package only independently matched Assembly PDF summary totals as guarded JSON revisions."""

import copy
import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

from extract_assembly_summary_totals import corroborates, read_summary_pages
from preserve_archive_json import package


PENDING = 'Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.'
RECONCILED = 'Candidate rows transcribed from the detailed PDF; summary totals reconcile; publication review pending.'
NAME = 'pollmedia-ac-summary-corrections-20261001-v2'


def digest(body):
    return hashlib.sha256(body).hexdigest()


def file_digest(path):
    with path.open('rb') as stream:
        return hashlib.file_digest(stream, 'sha256').hexdigest()


def source_pdf(folder, data):
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    source_file = data.get('source_file')
    sources = [item for item in manifest.get('files', []) if item.get('file') == source_file]
    if (manifest.get('url') != data.get('source_url') or len(sources) != 1
            or sources[0]['sha256'] != data.get('source_sha256')):
        raise ValueError('Official source identity or recorded checksum differs: ' + folder.name)
    source = folder / source_file
    if not source.is_file() or source.is_symlink() or source.suffix.lower() != '.pdf':
        raise ValueError('Official source PDF is unavailable locally: ' + folder.name)
    if file_digest(source) != sources[0]['sha256']:
        raise ValueError('Official source PDF checksum differs: ' + folder.name)
    return source


def revised_records(data, summaries):
    revised = copy.deepcopy(data)
    count = 0
    for record in revised['records']:
        if (record.get('status') != 'needs_review' or record.get('error') != PENDING
                or record.get('votes_polled') is not None
                or not isinstance(record.get('valid_candidate_votes'), int)):
            continue
        summary = summaries.get(record.get('code'))
        if (summary is None or not corroborates(record, summary)
                or record.get('summary_page') not in (None, summary['summary_page'])
                or record.get('summary_totals') not in (None, {})):
            continue
        record['original_extraction_warning'] = record['error']
        record['error'] = RECONCILED
        record['electors'] = summary['electors']
        record['votes_polled'] = summary['votes_polled']
        record['summary_totals'] = {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
        record['summary_page'] = summary['summary_page']
        count += 1
    return revised, count


def build(root):
    archive_root = root / 'application/storage/app/private'
    exports = root / 'exports'
    exports.mkdir(exist_ok=True)
    bundle = exports / (NAME + '.zip')
    if bundle.exists() or bundle.with_suffix('.sha256').exists():
        raise FileExistsError('Assembly correction bundle already exists')
    details = []
    with tempfile.TemporaryDirectory(prefix='assembly-summary-', dir=exports) as temporary:
        temporary = Path(temporary).resolve()
        if not temporary.is_relative_to(exports.resolve()):
            raise ValueError('Temporary bundle directory is outside exports')
        staged = temporary / 'archive'
        packages = temporary / 'packages'
        packages.mkdir()
        for extraction in sorted((archive_root / 'election-archive').glob('*/extraction.json')):
            old_bytes = extraction.read_bytes()
            data = json.loads(old_bytes)
            if data.get('kind') != 'ac' or not any(
                record.get('error') == PENDING and record.get('votes_polled') is None
                for record in data.get('records', [])
            ):
                continue
            source = source_pdf(extraction.parent, data)
            revised, count = revised_records(data, read_summary_pages(source))
            if count == 0:
                continue
            old_sha = digest(old_bytes)
            new_bytes = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
            if new_bytes == old_bytes or len(revised['records']) != len(data['records']):
                raise ValueError('An Assembly correction changed record coverage unexpectedly')
            edition = extraction.parent.name
            snapshot_path = f'election-archive/{edition}/extraction-{old_sha}.json'
            revision_path = f'election-archive/{edition}/extraction.json'
            for relative, body in [(snapshot_path, old_bytes), (revision_path, new_bytes)]:
                target = staged / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(body)
            details.append({'edition': edition, 'year': data['year'],
                            'source_url': data['source_url'], 'source_sha256': data['source_sha256'],
                            'revised_records': count, 'previous_sha256': old_sha,
                            'new_sha256': digest(new_bytes), 'snapshot_path': snapshot_path,
                            'revision_path': revision_path})
            if extraction.read_bytes() != old_bytes:
                raise ValueError('An archived extraction changed during source review')
        if not details:
            raise ValueError('No official summary totals reconciled')

        packages_by_type = {'snapshot': [], 'correction': []}
        for detail in details:
            edition = detail['edition']
            for kind, relative in [('snapshot', detail['snapshot_path']), ('correction', detail['revision_path'])]:
                bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
                output = packages / f'{kind}-{edition}.zip'
                package(staged, output, 'election-archive', bucket, 8, [relative],
                        detail['previous_sha256'] if kind == 'correction' else None,
                        detail['snapshot_path'] if kind == 'correction' else None)
                packages_by_type[kind].append(output)

        inner = packages_by_type['snapshot'] + packages_by_type['correction']
        checksums = [(path.name, file_digest(path)) for path in inner]
        ids = ','.join(detail['edition'] for detail in details)
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
        partial = bundle.with_suffix('.zip.partial')
        if partial.exists():
            raise FileExistsError(partial)
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for path in inner:
                zipped.write(path, path.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{checksum}  {name}\n' for name, checksum in checksums))
            zipped.writestr('ARCHIVES', ''.join(detail['edition'] + '\n' for detail in details))
            zipped.writestr('AUDIT.json', json.dumps({'scope': 'Assembly source summary corrections; accepted contests unchanged',
                                                    'editions': details}, ensure_ascii=False, indent=2))
            zipped.writestr('IMPORT.sh', script)
        partial.replace(bundle)
    checksum = file_digest(bundle)
    bundle.with_suffix('.sha256').write_text(f'{checksum}  {bundle.name}\n', encoding='ascii')
    return {'bundle': str(bundle), 'sha256': checksum, 'editions': len(details),
            'revised_records': sum(detail['revised_records'] for detail in details),
            'bytes': bundle.stat().st_size}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
