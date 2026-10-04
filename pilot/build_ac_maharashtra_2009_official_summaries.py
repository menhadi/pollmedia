"""Package source-verified turnout and declarations for Maharashtra AC 2009."""

import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

from audit_ac_maharashtra_2009_summaries import EDITION, ROOT, SOURCE_FILE, audit
from build_pc_ac_zero_turnout_bundle import import_script
from preserve_archive_json import package


NAME = 'pollmedia-ac-maharashtra-2009-official-summaries-20261004'
OLD_ERROR = ('Candidate rows transcribed from the detailed PDF; independent summary reconciliation is pending.; '
             'Some candidate text could not be parsed; see the original PDF.; '
             'Detailed totals are missing or use an unsupported layout.')
NOTE = ('Official constituency summary confirms voters, winner and margin. '
        'Detailed candidate rows remain preserved for review; see the official report.')
DISCREPANCY_NOTES = {
    134: ('Official summary confirms voters and BJP winner. It prints a 2,291 margin, but the '
          'printed 46,996 and 44,804 candidate votes differ by 2,192; margin is withheld for review.'),
    178: ('Official summary confirms voters and INC winner. It prints a 9,710 margin, but the '
          'printed 52,492 and 42,783 candidate votes differ by 9,709; margin is withheld for review.'),
}


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def revised_edition(root: Path = ROOT) -> tuple[bytes, bytes, dict]:
    old_body, summaries, source = audit(root)
    old = json.loads(old_body)
    revised = json.loads(old_body)
    if len(summaries) != 288 or {row['code'] for row in summaries} != set(range(1, 289)):
        raise ValueError('Maharashtra 2009 summary inventory differs')
    discrepancies = {row['code'] for row in summaries if not row['margin_reconciles']}
    if discrepancies != set(DISCREPANCY_NOTES):
        raise ValueError('Maharashtra 2009 official margin discrepancies changed')
    by_code = {row['code']: row for row in summaries}
    for record in revised['records']:
        code = record['code']
        source_row = by_code[code]
        if (record['number_of_seats'] != 1 or record['state_name'] != 'Maharashtra'
                or record['status'] != 'needs_review' or record['error'] != OLD_ERROR
                or record['electors'] != source_row['electors']
                or record.get('votes_polled') is not None
                or record.get('summary_totals') is not None
                or record.get('summary_result') is not None
                or record.get('summary_winner_only') is not None
                or record.get('source_warning_code') is not None):
            raise ValueError(f'Maharashtra 2009 extracted seat differs: {code}')
        record['previous_review_note'] = record['error']
        record['error'] = DISCREPANCY_NOTES.get(code, NOTE)
        record['votes_polled'] = source_row['votes_polled']
        record['valid_candidate_votes'] = source_row['valid_candidate_votes']
        record['summary_totals'] = {key: source_row[key]
                                    for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
        record['summary_page'] = source_row['summary_page']
        record['summary_source_file'] = SOURCE_FILE
        record['summary_source_sha256'] = source['source_sha256']
        record['source_warning_code'] = 'official_summary_turnout_only'
        if code in discrepancies:
            record['summary_winner_only'] = source_row['result']
        else:
            record['summary_result'] = source_row['result']
    common = {'previous_review_note', 'error', 'votes_polled', 'valid_candidate_votes',
              'summary_totals', 'summary_page', 'summary_source_file',
              'summary_source_sha256', 'source_warning_code'}
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        result_field = 'summary_winner_only' if before['code'] in discrepancies else 'summary_result'
        if before['code'] != after['code'] or changed != common | {result_field}:
            raise ValueError('Unrelated Maharashtra 2009 evidence changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    return old_body, new_body, {'edition': EDITION, 'year': 2009,
                                'previous_sha256': digest(old_body), 'new_sha256': digest(new_body),
                                'source_url': source['source_url'], 'source_file': SOURCE_FILE,
                                'source_sha256': source['source_sha256'],
                                'summaries': len(summaries), 'declared_results': 286,
                                'winner_only_codes': sorted(discrepancies)}


def build(root: Path = ROOT) -> dict:
    output = root / 'exports' / f'{NAME}.zip'
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    old_body, new_body, audit_row = revised_edition(root)
    with tempfile.TemporaryDirectory(prefix='ac-maharashtra-2009-', dir=output.parent) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{audit_row["previous_sha256"]}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        inner = []
        for kind, relative, body in (('snapshot', snapshot, old_body), ('correction', revision, new_body)):
            target = staged / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staged, path, 'election-archive', bucket, 8, [relative],
                    audit_row['previous_sha256'] if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as archive:
            for path in inner:
                archive.write(path, path.name)
            archive.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            archive.writestr('ARCHIVES', EDITION + '\n')
            archive.writestr('AUDIT.json', json.dumps({'scope': 'Maharashtra 2009 AC official constituency summaries',
                                                      **audit_row}, indent=2))
            archive.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    checksum = digest(output.read_bytes())
    output.with_suffix('.sha256').write_bytes(f'{checksum}  {output.name}\n'.encode('ascii'))
    return {'bundle': str(output), 'sha256': checksum, **audit_row}


if __name__ == '__main__':
    print(json.dumps(build()))
