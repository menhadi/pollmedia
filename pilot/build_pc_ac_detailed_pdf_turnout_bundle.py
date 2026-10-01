"""Package turnout printed in archived ECI detailed-result PDFs but absent from summaries."""

import copy
import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

import fitz

from audit_pc_ac_zero_values import IMPORTED_PACKAGES, PROPOSED_PACKAGES, correction_index, effective_body
from build_pc_ac_residual_turnout_bundle import HEADING, SYMBOL_TOTAL, file_sha256, sha256
from build_pc_ac_zero_turnout_bundle import import_script
from extract_saved_summary_ocr import normalized
from preserve_archive_json import package


NAME = 'pollmedia-pc-ac-detailed-source-turnout-corrections-20261001-v2'
PRIOR_PACKAGES = IMPORTED_PACKAGES + PROPOSED_PACKAGES + (
    'pollmedia-ac-residual-turnout-corrections-20261001-v4.zip',
)
TARGETS = {
    'c7a9e523186004c713736633': {141, 173, 187, 200, 256, 257, 258, 259, 260, 264, 394},
    'ed5e5cef04a9edb821cf27af': {11},
    'd7365c7939cfc38b09eb9581': {500, 501},
}
EXPECTED_RECORDS = {
    'c7a9e523186004c713736633': 403,
    'ed5e5cef04a9edb821cf27af': 60,
    'd7365c7939cfc38b09eb9581': 520,
}
PC1967 = 'd7365c7939cfc38b09eb9581'


def verified_detail_pdf(folder: Path, data: dict) -> tuple[Path, str]:
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    items = [item for item in manifest['files'] if item['name'].casefold() == 'detailed results.pdf']
    if len(items) != 1 or manifest['url'] != data['source_url']:
        raise ValueError('Detailed PDF identity differs: ' + folder.name)
    item = items[0]
    path = folder / item['file']
    if (path.resolve().parent != folder.resolve() or path.is_symlink()
            or item['source_page'] != data['source_url']
            or file_sha256(path) != item['sha256']):
        raise ValueError('Detailed PDF checksum differs: ' + folder.name)
    return path, item['sha256']


def verified_pc_summary_pdf(folder: Path, data: dict) -> tuple[Path, str]:
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    items = [item for item in manifest['files'] if item['name'] == '1967 (Vol II).pdf']
    if len(items) != 1 or manifest['url'] != data['source_url']:
        raise ValueError('1967 PC summary identity differs')
    item = items[0]
    path = folder / item['file']
    if (path.resolve().parent != folder.resolve() or path.is_symlink()
            or item['source_page'] != data['source_url']
            or file_sha256(path) != item['sha256']):
        raise ValueError('1967 PC summary checksum differs')
    return path, item['sha256']


def read_printed_turnout(pdf: Path, records: list[dict], codes: set[int], expected_records: int) -> dict[int, dict]:
    if len(records) != expected_records or len({r['code'] for r in records}) != len(records):
        raise ValueError('Source record coverage or identity differs')
    with fitz.open(pdf) as doc:
        pages = [page.get_text() for page in doc]
    offsets, length = [], 0
    for number, page in enumerate(pages, 1):
        offsets.append((length, number))
        length += len(page) + 1
    text = '\n'.join(pages)
    headings = list(HEADING.finditer(text))
    if len(headings) != expected_records:
        raise ValueError('Official detailed PDF constituency coverage differs')
    by_code = {record['code']: record for record in records if record['code'] in codes}
    found = {}
    for index, heading in enumerate(headings):
        code = int(heading['code'])
        if code not in codes:
            continue
        record = by_code[code]
        source_name = normalized(heading['name'])
        record_name = normalized(record['name'])
        electors = int(heading['electors'])
        block = text[heading.end():headings[index + 1].start() if index + 1 < len(headings) else len(text)]
        totals = list(SYMBOL_TOTAL.finditer(block))
        if (code in found or not source_name.startswith(record_name)
                or record.get('electors') not in (None, 0, electors)
                or record.get('votes_polled') not in (None, 0)
                or len(totals) != 1):
            raise ValueError(f'Official PDF turnout identity differs for seat {code}')
        total = totals[0]
        general, postal, voters = (int(total[key]) for key in ('general', 'postal', 'total'))
        percent = float(total['percent'])
        candidates = record['candidates']
        if (not candidates or any(type(c.get('votes')) is not int for c in candidates)
                or sum(c['votes'] for c in candidates) != voters
                or not 0 < general <= voters <= electors
                or general + postal != voters
                or abs(100 * voters / electors - percent) > 0.0051):
            raise ValueError(f'Official PDF turnout arithmetic differs for seat {code}')
        page_at = heading.end() + total.start()
        page = max(number for offset, number in offsets if offset <= page_at)
        found[code] = {'electors': electors, 'votes_polled': voters,
                       'general_votes': general, 'postal_votes': postal,
                       'source_turnout_percent': percent, 'source_page': page,
                       'method': 'official detailed-result PDF turnout row; candidates reconcile'}
    if set(found) != codes:
        raise ValueError('Official PDF turnout coverage differs: ' + str(sorted(codes - set(found))))
    return found


def read_pc1967_summary(pdf: Path, records: list[dict]) -> dict[int, dict]:
    if len(records) != 520 or len({r['code'] for r in records}) != 520:
        raise ValueError('1967 PC source record coverage differs')
    found = {}
    with fitz.open(pdf) as doc:
        for code in sorted(TARGETS[PC1967]):
            record = next(r for r in records if r['code'] == code)
            page = record.get('summary_page')
            summary = record.get('summary_totals') or {}
            if (not isinstance(page, int) or not 1 <= page <= len(doc)
                    or record.get('votes_polled') not in (None, 0)
                    or record.get('electors') != summary.get('electors')):
                raise ValueError(f'1967 PC {code} summary identity differs')
            text = doc[page - 1].get_text()
            name = record['name'].split('/')[-1].strip()
            local_code = code - 499
            source_identity = re.search(r'\bU20\s*' + str(local_code) + r'\s+' + re.escape(name)
                                        + r'\s+CONSTITUENCY\s*:', text, re.I)
            totals = re.search(r'VOTES\s+IV\.\s+POLLED\s+1\.\s+VALID\s+2\.\s+REJECTED\s+3\.\s+'
                               r'(\d+)\s+(\d+)\s+(\d+)', text, re.I)
            if (not source_identity or not totals or 'General Elections, 1967' not in text
                    or 'Delhi' not in text or 'ELECTORS WHO VOTED' not in text):
                raise ValueError(f'1967 PC {code} printed summary is not identifiable')
            voters, valid, rejected = (int(value) for value in totals.groups())
            electors = summary['electors']
            if (not 0 < valid < voters <= electors or valid + rejected != voters
                    or voters != summary.get('votes_polled')
                    or valid != summary.get('valid_candidate_votes')
                    or text.count(str(electors)) < 3
                    or text.count(str(voters)) < 3):
                raise ValueError(f'1967 PC {code} printed summary does not reconcile')
            found[code] = {'electors': electors, 'votes_polled': voters,
                           'valid_votes': valid, 'rejected_votes': rejected,
                           'source_page': page, 'method': 'official 1967 constituency summary; detail rows unresolved'}
    return found


def build(root: Path) -> dict:
    exports = root / 'exports'
    bundle = exports / (NAME + '.zip')
    if bundle.exists() or bundle.with_suffix('.sha256').exists():
        raise FileExistsError(bundle)
    revisions = correction_index(root, PRIOR_PACKAGES)
    details = []
    with tempfile.TemporaryDirectory(prefix='ac-detailed-turnout-', dir=exports) as temporary:
        staged = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        for edition, codes in TARGETS.items():
            folder = root / 'application/storage/app/private/election-archive' / edition
            old_body = effective_body(folder / 'extraction.json', revisions.get(edition, []))
            data = json.loads(old_body)
            if edition == PC1967:
                if data['kind'] != 'pc' or data['year'] != 1967:
                    raise ValueError('Parliament edition identity differs')
                pdf, pdf_sha = verified_pc_summary_pdf(folder, data)
                totals = read_pc1967_summary(pdf, data['records'])
            else:
                if data['kind'] != 'ac' or data['year'] != 2017:
                    raise ValueError('Assembly edition identity differs')
                pdf, pdf_sha = verified_detail_pdf(folder, data)
                totals = read_printed_turnout(pdf, data['records'], codes, EXPECTED_RECORDS[edition])
            revised = copy.deepcopy(data)
            for record in revised['records']:
                source_total = totals.get(record['code'])
                if source_total is None:
                    continue
                record['original_extraction_warning'] = record.get('error') or ''
                record['error'] = (('Official constituency summary prints turnout; detailed candidate rows remain unresolved. '
                                    if edition == PC1967 else 'Official detailed-result PDF prints turnout. ')
                                   + 'Original extraction and candidate warnings remain available for review.')
                record['source_warning_code'] = ('official_summary_turnout_detail_unresolved' if edition == PC1967
                                                 else 'official_detailed_pdf_turnout')
                record['votes_polled'] = source_total['votes_polled']
                if record.get('electors') in (None, 0):
                    record['electors'] = source_total['electors']
                record['turnout_totals'] = source_total
                record['turnout_source_page'] = source_total['source_page']
                record['turnout_source_file'] = pdf.name
                record['turnout_source_sha256'] = pdf_sha
            for before, after in zip(data['records'], revised['records']):
                if (before['code'] != after['code'] or before['name'] != after['name']
                        or before['candidates'] != after['candidates']
                        or before.get('status') != after.get('status')
                        or before.get('valid_candidate_votes') != after.get('valid_candidate_votes')
                        or (before.get('electors') not in (None, 0) and before['electors'] != after['electors'])
                        or (before.get('votes_polled') not in (None, 0) and before['votes_polled'] != after['votes_polled'])):
                    raise ValueError('Existing candidate or nonblank total changed')
            new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
            old_sha = sha256(old_body)
            snapshot = f'election-archive/{edition}/extraction-{old_sha}.json'
            revision = f'election-archive/{edition}/extraction.json'
            for relative, body in ((snapshot, old_body), (revision, new_body)):
                path = staged / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(body)
            details.append({'edition': edition, 'kind': data['kind'], 'year': data['year'], 'source_url': data['source_url'],
                            'pdf_file': pdf.name, 'pdf_sha256': pdf_sha,
                            'previous_sha256': old_sha, 'new_sha256': sha256(new_body),
                            'codes': sorted(totals),
                            'totals': {str(code): totals[code] for code in sorted(totals)}})
        inner = []
        for prefix in ('snapshot', 'correction'):
            for detail in details:
                edition = detail['edition']
                previous = f'election-archive/{edition}/extraction-{detail["previous_sha256"]}.json'
                relative = previous if prefix == 'snapshot' else f'election-archive/{edition}/extraction.json'
                output = packages / f'{prefix}-{edition}.zip'
                bucket = hashlib.sha256(relative.encode()).digest()[0] % 8
                package(staged, output, 'election-archive', bucket, 8, [relative],
                        detail['previous_sha256'] if prefix == 'correction' else None,
                        previous if prefix == 'correction' else None)
                inner.append(output)
        partial = bundle.with_suffix('.zip.partial')
        if partial.exists():
            raise FileExistsError(partial)
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for item in inner:
                zipped.write(item, item.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{file_sha256(item)}  {item.name}\n' for item in inner))
            zipped.writestr('ARCHIVES', ''.join(detail['edition'] + '\n' for detail in details))
            zipped.writestr('AUDIT.json', json.dumps({'scope': '12 printed AC 2017 and 2 PC 1967 turnout totals; no candidates changed',
                                                   'editions': details}, ensure_ascii=False, indent=2))
            zipped.writestr('IMPORT.sh', import_script([detail['edition'] for detail in details]))
        partial.replace(bundle)
    digest = file_sha256(bundle)
    bundle.with_suffix('.sha256').write_bytes((digest + '  ' + bundle.name + '\n').encode('ascii'))
    return {'bundle': str(bundle), 'sha256': digest, 'editions': len(details),
            'corrected_rows': sum(len(detail['codes']) for detail in details)}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
