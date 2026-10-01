"""Audit only blank, zero, or invalid PC/AC turnout across official editions."""

import argparse
from collections import Counter
import csv
import hashlib
import io
import json
from pathlib import Path
import zipfile


IMPORTED_PACKAGES = (
    'pollmedia-pc-ac-corrections-20260930.zip',
    'pollmedia-pc-2009-state-correction-bundle-20260930.zip',
    'pollmedia-ac-summary-corrections-20261001-v7.zip',
    'pollmedia-ac-workbook-summary-corrections-20261001-v5.zip',
)
PROPOSED_PACKAGES = (
    'pollmedia-bihar-2005-turnout-correction-20261001.zip',
    'pollmedia-pc-ac-zero-turnout-corrections-20261001-v3.zip',
    'pollmedia-arunachal-2014-turnout-correction-20261001.zip',
    'pollmedia-gujarat-2012-turnout-correction-20261001.zip',
)


def correction_index(root: Path, packages: tuple[str, ...]) -> dict:
    index = {}
    for filename in packages:
        path = root / 'exports' / filename
        expected = path.with_suffix('.sha256').read_text(encoding='ascii').split()[0]
        with path.open('rb') as source:
            if hashlib.file_digest(source, 'sha256').hexdigest() != expected:
                raise ValueError('Bundle checksum differs: ' + filename)
        with zipfile.ZipFile(path) as outer:
            for member in outer.namelist():
                if 'correction' not in member or not member.endswith('.zip'):
                    continue
                with zipfile.ZipFile(io.BytesIO(outer.read(member))) as inner:
                    manifest = json.loads(inner.read('manifest.json'))['files'][0]
                    edition = manifest['path'].split('/')[1]
                    index.setdefault(edition, []).append((path, member))
    return index


def effective_body(path: Path, revisions: list[tuple[Path, str]]) -> bytes:
    body = path.read_bytes()
    for outer_path, member in revisions:
        with zipfile.ZipFile(outer_path) as outer:
            with zipfile.ZipFile(io.BytesIO(outer.read(member))) as inner:
                manifest = json.loads(inner.read('manifest.json'))['files'][0]
                if manifest['path'] != f'election-archive/{path.parent.name}/extraction.json':
                    raise ValueError('Correction edition identity differs')
                digest = hashlib.sha256(body).hexdigest()
                if digest == manifest['sha256']:
                    continue
                if digest != manifest['replaces_sha256']:
                    raise ValueError('Correction prior SHA differs for edition ' + path.parent.name)
                body = inner.read(manifest['path'])
                if hashlib.sha256(body).hexdigest() != manifest['sha256']:
                    raise ValueError('Correction payload checksum differs')
    return body


def audit(root: Path, include_proposed: bool = False) -> tuple[dict, list[dict]]:
    packages = IMPORTED_PACKAGES + (PROPOSED_PACKAGES if include_proposed else ())
    revisions = correction_index(root, packages)
    archive = root / 'application/storage/app/private/election-archive'
    year_counts = Counter()
    rows = []
    editions = records = 0
    for path in sorted(archive.glob('*/extraction.json')):
        data = json.loads(effective_body(path, revisions.get(path.parent.name, [])))
        editions += 1
        kind, year = data['kind'], data['year']
        if kind not in ('pc', 'ac'):
            raise ValueError('Unexpected election kind')
        for record in data['records']:
            records += 1
            year_counts[(kind, year, 'records')] += 1
            electors, voters = record.get('electors'), record.get('votes_polled')
            reasons = []
            if electors is None or electors == 0:
                reasons.append('electors_blank_or_zero')
            if voters is None or voters == 0:
                reasons.append('voters_blank_or_zero')
            if (type(electors) is int and electors > 0 and type(voters) is int
                    and voters > electors):
                reasons.append('voters_exceed_electors')
            for reason in reasons:
                year_counts[(kind, year, reason)] += 1
            if reasons:
                source = path.parent / (data.get('source_file') or '')
                rows.append({'kind': kind, 'year': year,
                             'state_as_recorded': record.get('state_name') or record.get('state_code') or '',
                             'edition_id': path.parent.name, 'record_code': record.get('code'),
                             'constituency': record.get('name') or record.get('constituency_name') or '',
                             'electors': electors, 'votes_polled': voters,
                             'reason': '|'.join(reasons), 'source_file_present': source.is_file(),
                             'source_url': data.get('source_url') or '',
                             'summary_page': record.get('summary_page') or '',
                             'detail_page': record.get('detail_page') or '',
                             'data_note': record.get('error') or ''})
    summary = {'scope': 'Archived PC/AC constituency source records; not accepted contest coverage',
               'projection': 'after proposed corrections' if include_proposed else 'after imported corrections',
               'editions': editions, 'records': records,
               'year_counts': [dict(kind=kind, year=year, records=year_counts[(kind, year, 'records')],
                                    electors_blank_or_zero=year_counts[(kind, year, 'electors_blank_or_zero')],
                                    voters_blank_or_zero=year_counts[(kind, year, 'voters_blank_or_zero')],
                                    voters_exceed_electors=year_counts[(kind, year, 'voters_exceed_electors')])
                               for kind, year in sorted({(key[0], key[1]) for key in year_counts})]}
    return summary, rows


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument('--include-proposed', action='store_true')
    parser.add_argument('--output-prefix', type=Path)
    args = parser.parse_args()
    summary, rows = audit(args.root, args.include_proposed)
    if args.output_prefix:
        args.output_prefix.parent.mkdir(parents=True, exist_ok=True)
        args.output_prefix.with_name(args.output_prefix.name + '-summary.json').write_text(
            json.dumps(summary, ensure_ascii=False, indent=2), encoding='utf-8')
        with args.output_prefix.with_name(args.output_prefix.name + '-gaps.csv').open('w', encoding='utf-8', newline='') as target:
            writer = csv.DictWriter(target, fieldnames=list(rows[0]))
            writer.writeheader()
            writer.writerows(rows)
    totals = Counter()
    for year in summary['year_counts']:
        for field in ('electors_blank_or_zero', 'voters_blank_or_zero', 'voters_exceed_electors'):
            totals[(year['kind'], field)] += year[field]
    print(json.dumps({'projection': summary['projection'], 'editions': summary['editions'],
                      'records': summary['records'], 'gap_rows': len(rows), 'totals': dict((str(key), value)
                                                                                       for key, value in totals.items())},
                     ensure_ascii=False))
