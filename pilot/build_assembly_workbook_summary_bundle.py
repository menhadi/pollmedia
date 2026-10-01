"""Package independently matched Assembly workbook and PDF summary totals."""

import copy
import hashlib
import json
from pathlib import Path
import re
import tempfile
import zipfile

from build_assembly_summary_bundle import digest, file_digest, source_summaries
from extract_assembly_summary_totals import corroborates
from preserve_archive_json import package


NAME = 'pollmedia-ac-workbook-summary-corrections-20261001-v5'
PENDING = 'Candidate cells transcribed from the official workbook; independent summary reconciliation is pending.'
AMBIGUOUS = 'The source total column is preserved by its original label; voter and valid-vote meanings require summary verification.'
TOTAL_MISMATCH = 'Candidate and NOTA sum differs from the reported table total.'
RECONCILED = 'Official constituency summary confirms voters and candidate votes; publication review pending.'
RECONCILED_NOTA = 'Official constituency summary confirms voters and candidate votes; its valid-vote total includes NOTA. Publication review pending.'
RECONCILED_MISMATCH = 'Official constituency summary confirms voters and candidate votes; the workbook total differs and is preserved for review.'
RECONCILED_CANDIDATE_DIFFERENCE = 'Official constituency summary confirms voters; its valid-vote total differs slightly from the preserved candidate rows. Publication review pending.'
RECONCILED_RECOVERED = 'Official constituency summary confirms electors, voters and candidate votes; the source elector components differ slightly. Publication review pending.'
DUPLICATE_NAMES = '; Duplicate candidate names and parties appear in the source; rows are retained.'
RECOVERED = re.compile(re.escape(PENDING + ' Candidate rows recovered from the same archived source; earlier extraction note: Elector totals conflict: detailed report ') + r'(\d+); summary components (\d+)\.')


def revised_records(data, summaries, secondary_source):
    """Add only matched source totals; preserve every candidate cell and source warning."""
    revised = copy.deepcopy(data)
    changed = 0
    if secondary_source is None:
        return revised, changed
    for record in revised.get('records', []):
        original_error = record.get('error') or ''
        recovered = RECOVERED.fullmatch(original_error)
        eligible = original_error in (PENDING, PENDING + '; ' + AMBIGUOUS, PENDING + '; ' + TOTAL_MISMATCH,
                                      PENDING + DUPLICATE_NAMES, PENDING + DUPLICATE_NAMES + '; ' + AMBIGUOUS)
        if (record.get('status') != 'needs_review'
                or (not eligible and not recovered)
                or record.get('votes_polled') is not None
                or record.get('number_of_seats', 1) != 1
                or record.get('summary_totals') not in (None, {})
                or record.get('summary_page') is not None):
            continue
        summary = summaries.get(record.get('code'))
        if summary is None or not corroborates(record, summary, allow_inclusive_nota=True,
                                                allow_candidate_difference=True):
            continue
        if recovered and (int(recovered[1]) != summary['electors']
                          or not 0 < abs(int(recovered[1]) - int(recovered[2])) <= 2):
            continue
        candidates = record.get('candidates') or []
        if (len(candidates) < 2
                or any(type(candidate.get('votes')) is not int or candidate['votes'] < 0
                       or not str(candidate.get('candidate_name') or '').strip()
                       or not str(candidate.get('party_at_election') or '').strip()
                       or type(candidate.get('general_votes')) is not int
                       or type(candidate.get('postal_votes')) is not int
                       or candidate['votes'] != candidate['general_votes'] + candidate['postal_votes']
                       or not candidate.get('source_sheet')
                       or type(candidate.get('workbook_row')) is not int
                       or candidate['workbook_row'] <= 0 for candidate in candidates)):
            continue
        keys = [(candidate['candidate_name'].strip().casefold(),
                 candidate['party_at_election'].strip().casefold(), candidate['votes'])
                for candidate in candidates]
        if len(set(keys)) != len(candidates):
            continue
        totals = record.get('reported_totals') or []
        candidate_sum = sum(candidate['votes'] for candidate in candidates)
        matched_total = totals[0].get('value') == candidate_sum if len(totals) == 1 else False
        expected_total = summary['valid_candidate_votes'] + summary.get('nota_votes', 0)
        candidate_difference = candidate_sum - expected_total
        if recovered and (totals or candidate_difference != 0):
            continue
        if not recovered and (len(totals) != 1 or totals[0].get('label') not in ('Total Votes', 'Total valid votes polled +NOTA')
                              or type(totals[0].get('value')) is not int
                              or (not matched_total and original_error != PENDING + '; ' + TOTAL_MISMATCH)
                              or (candidate_difference != 0 and not matched_total)):
            continue
        has_nota = any(candidate.get('is_nota') is True for candidate in candidates)
        record['original_extraction_warning'] = record['error']
        record['error'] = (RECONCILED_RECOVERED if recovered else
                           RECONCILED_CANDIDATE_DIFFERENCE if candidate_difference else
                           RECONCILED_MISMATCH if not matched_total else
                           RECONCILED_NOTA if has_nota and 'nota_votes' not in summary else RECONCILED)
        record['source_warning_code'] = 'workbook_pdf_summary'
        if recovered:
            record['source_discrepancy'] = {'field': 'elector_components', 'component_value': int(recovered[2]),
                                            'summary_value': summary['electors']}
            record['electors'] = summary['electors']
        elif candidate_difference:
            record['source_discrepancy'] = {'field': 'candidate_total', 'candidate_sum': candidate_sum,
                                            'summary_value': expected_total, 'difference': candidate_difference}
        elif not matched_total:
            record['source_discrepancy'] = {'field': 'reported_total', 'workbook_value': totals[0]['value'],
                                            'candidate_sum': candidate_sum, 'summary_value': expected_total}
        record['votes_polled'] = summary['votes_polled']
        record['summary_totals'] = {key: summary[key] for key in
                                    ('electors', 'votes_polled', 'valid_candidate_votes', 'nota_votes') if key in summary}
        record['summary_page'] = summary['summary_page']
        record['summary_source_file'] = secondary_source['source_file']
        record['summary_source_sha256'] = secondary_source['source_sha256']
        changed += 1
    return revised, changed


def build(root):
    archive_root = root / 'application/storage/app/private/election-archive'
    exports = root / 'exports'
    exports.mkdir(exist_ok=True)
    bundle = exports / (NAME + '.zip')
    if bundle.exists() or bundle.with_suffix('.sha256').exists():
        raise FileExistsError('Workbook summary correction bundle already exists')
    details = []
    skipped = []
    with tempfile.TemporaryDirectory(prefix='workbook-summary-', dir=exports) as temporary:
        temporary = Path(temporary).resolve()
        if not temporary.is_relative_to(exports.resolve()):
            raise ValueError('Temporary bundle directory is outside exports')
        staged = temporary / 'archive'
        packages = temporary / 'packages'
        packages.mkdir()
        for extraction in sorted(archive_root.glob('*/extraction.json')):
            old_bytes = extraction.read_bytes()
            data = json.loads(old_bytes)
            if data.get('kind') != 'ac' or not any(
                (record.get('error') in (PENDING, PENDING + '; ' + AMBIGUOUS, PENDING + '; ' + TOTAL_MISMATCH,
                                         PENDING + DUPLICATE_NAMES, PENDING + DUPLICATE_NAMES + '; ' + AMBIGUOUS)
                 or RECOVERED.fullmatch(record.get('error') or ''))
                and record.get('votes_polled') is None for record in data.get('records', [])
            ):
                continue
            try:
                summaries, secondary = source_summaries(extraction.parent, data, allow_workbook=True)
            except ValueError as error:
                if 'unavailable locally' not in str(error):
                    raise
                skipped.append({'edition': extraction.parent.name, 'reason': str(error)})
                continue
            revised, count = revised_records(data, summaries, secondary)
            if count == 0:
                skipped.append({'edition': extraction.parent.name, 'reason': 'No independently matched official constituency summary'})
                continue
            old_sha = digest(old_bytes)
            new_bytes = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
            if new_bytes == old_bytes or len(revised['records']) != len(data['records']):
                raise ValueError('A workbook correction changed record coverage unexpectedly')
            edition = extraction.parent.name
            snapshot = f'election-archive/{edition}/extraction-{old_sha}.json'
            correction = f'election-archive/{edition}/extraction.json'
            for relative, body in ((snapshot, old_bytes), (correction, new_bytes)):
                target = staged / relative
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(body)
            details.append({'edition': edition, 'year': data['year'], 'source_url': data['source_url'],
                            'source_sha256': data['source_sha256'], 'revised_records': count,
                            'previous_sha256': old_sha, 'new_sha256': digest(new_bytes),
                            'snapshot_path': snapshot, 'revision_path': correction})
            if extraction.read_bytes() != old_bytes:
                raise ValueError('An archived extraction changed during source review')
        if not details:
            raise ValueError('No workbook records matched official PDF summaries')
        inner = []
        for detail in details:
            for kind, relative in (('snapshot', detail['snapshot_path']), ('correction', detail['revision_path'])):
                bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
                output = packages / f'{kind}-{detail["edition"]}.zip'
                package(staged, output, 'election-archive', bucket, 8, [relative],
                        detail['previous_sha256'] if kind == 'correction' else None,
                        detail['snapshot_path'] if kind == 'correction' else None)
                inner.append(output)
        checksums = [(path.name, file_digest(path)) for path in inner]
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
'''
        partial = bundle.with_suffix('.zip.partial')
        if partial.exists():
            raise FileExistsError(partial)
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for path in inner:
                zipped.write(path, path.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{checksum}  {name}\n' for name, checksum in checksums))
            zipped.writestr('ARCHIVES', ''.join(detail['edition'] + '\n' for detail in details))
            zipped.writestr('AUDIT.json', json.dumps({'scope': 'Verified Assembly workbook/PDF summary corrections; accepted contests unchanged',
                                                    'editions': details, 'skipped': skipped}, ensure_ascii=False, indent=2))
            zipped.writestr('IMPORT.sh', script)
        partial.replace(bundle)
    checksum = file_digest(bundle)
    bundle.with_suffix('.sha256').write_text(f'{checksum}  {bundle.name}\n', encoding='ascii')
    return {'bundle': str(bundle), 'sha256': checksum, 'editions': len(details),
            'revised_records': sum(detail['revised_records'] for detail in details),
            'skipped_editions': len(skipped), 'bytes': bundle.stat().st_size}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
