"""Correct blank 2014 Arunachal AC turnout using saved OCR of official summaries."""

import copy
import hashlib
import json
from pathlib import Path
import tempfile
import zipfile

from build_pc_ac_zero_turnout_bundle import import_script
from extract_saved_summary_ocr import parse_arunachal_2014
from preserve_archive_json import package


EDITION = '08d56c7504299ea9043a1782'
NAME = 'pollmedia-arunachal-2014-turnout-correction-20261001'


def sha256(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def file_sha256(path: Path) -> str:
    with path.open('rb') as source:
        return hashlib.file_digest(source, 'sha256').hexdigest()


def build(root: Path) -> dict:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    original = (folder / 'extraction.json').read_bytes()
    data = json.loads(original)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if (data.get('kind') != 'ac' or data.get('year') != 2014
            or manifest.get('url') != data.get('source_url')
            or len(data.get('records', [])) != 60):
        raise ValueError('Arunachal 2014 edition identity or seat coverage differs')
    matches = [item for item in manifest['files'] if item['file'] == data['source_file']]
    if (len(matches) != 1 or matches[0]['sha256'] != data['source_sha256']
            or file_sha256(folder / data['source_file']) != data['source_sha256']
            or file_sha256(folder / data['ocr_file']) != data['ocr_sha256']):
        raise ValueError('Preserved official source or OCR checksum differs')
    saved_ocr = json.loads((folder / data['ocr_file']).read_text(encoding='utf-8'))
    if saved_ocr['source_sha256'] != data['source_sha256'] or len(saved_ocr['pages']) != 83:
        raise ValueError('Saved OCR does not belong to the official 83-page report')
    revised = copy.deepcopy(data)
    recovered = []
    for record in revised['records']:
        code = record['code']
        if type(code) is not int or not 1 <= code <= 60:
            raise ValueError('Seat code differs')
        summary = parse_arunachal_2014(saved_ocr['pages'][code + 11], record)
        if summary is None:
            continue
        original_warning = record.get('error') or ''
        note = ('Official constituency summary confirms printed turnout from the preserved OCR words; '
                'detailed candidate rows remain under review.')
        if (record.get('valid_candidate_votes') is not None
                and record['valid_candidate_votes'] != summary['valid_candidate_votes']):
            note += (f" Detailed valid votes: {record['valid_candidate_votes']:,}; "
                     f"official summary valid votes: {summary['valid_candidate_votes']:,}.")
        record['original_extraction_warning'] = original_warning
        record['error'] = note
        record['source_warning_code'] = 'official_summary_turnout_only'
        record['votes_polled'] = summary['votes_polled']
        record['summary_totals'] = {key: summary[key] for key in ('electors', 'votes_polled', 'valid_candidate_votes')}
        record['summary_page'] = summary['summary_page']
        record['summary_source_file'] = data['source_file']
        record['summary_source_sha256'] = data['source_sha256']
        record['summary_ocr_file'] = data['ocr_file']
        record['summary_ocr_sha256'] = data['ocr_sha256']
        recovered.append(code)
    if len(recovered) != 49:
        raise ValueError('Expected exactly 49 source-matched polled seats')
    for before, after in zip(data['records'], revised['records']):
        if (before['code'] != after['code'] or before['name'] != after['name']
                or before.get('candidates') != after.get('candidates')
                or before.get('electors') != after.get('electors')
                or before.get('status') != after.get('status')
                or before.get('valid_candidate_votes') != after.get('valid_candidate_votes')):
            raise ValueError('An existing election field changed')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    exports = root / 'exports'
    bundle = exports / (NAME + '.zip')
    if bundle.exists() or bundle.with_suffix('.sha256').exists():
        raise FileExistsError(bundle)
    with tempfile.TemporaryDirectory(prefix='arunachal-2014-', dir=exports) as temporary:
        staging = Path(temporary)
        archive = staging / 'archive'
        packages = staging / 'packages'
        packages.mkdir()
        old_sha = sha256(original)
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
        partial = bundle.with_suffix('.zip.partial')
        if partial.exists():
            raise FileExistsError(partial)
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for item in inner:
                zipped.write(item, item.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{file_sha256(item)}  {item.name}\n' for item in inner))
            zipped.writestr('ARCHIVES', EDITION + '\n')
            zipped.writestr('AUDIT.json', json.dumps({'scope': 'Arunachal AC 2014 blank turnout only',
                                                    'edition': EDITION, 'source_sha256': data['source_sha256'],
                                                    'ocr_sha256': data['ocr_sha256'], 'previous_sha256': old_sha,
                                                    'new_sha256': sha256(new_body), 'recovered_codes': recovered,
                                                    'unresolved_codes': sorted(set(range(1, 61)) - set(recovered))},
                                                   indent=2))
            zipped.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(bundle)
    if (folder / 'extraction.json').read_bytes() != original:
        raise RuntimeError('Local extraction changed during packaging')
    digest = file_sha256(bundle)
    bundle.with_suffix('.sha256').write_bytes((digest + '  ' + bundle.name + '\n').encode('ascii'))
    return {'bundle': str(bundle), 'sha256': digest, 'recovered': len(recovered),
            'unresolved': 60 - len(recovered), 'bytes': bundle.stat().st_size}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
