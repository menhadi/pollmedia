"""Attach the 2014 Arunachal result declarations to an already guarded turnout revision."""

import hashlib
import io
import json
from pathlib import Path
import re
import tempfile
import zipfile

from build_pc_ac_zero_turnout_bundle import import_script
from extract_saved_summary_ocr import parse_arunachal_2014
from preserve_archive_json import package


EDITION = '08d56c7504299ea9043a1782'
PRIOR_BUNDLE = 'pollmedia-arunachal-2014-turnout-correction-20261001.zip'
NAME = 'pollmedia-ac-arunachal-2014-summary-results-20261003'
PRIOR_SHA256 = '1edb604e13d7a5ef49c8e1b71bb51c810019910adbac00cdf0d692e88d879716'
SOURCE_SHA256 = '6cba3825ac440c1d8571d6604159bc6c2d45f2f5bb334bfef65d8e4d6655bfc4'
OCR_SHA256 = '03fc3606de1264fda607c85309d238d6713a4f19746c97e12b43f62688f96142'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def norm(value: str) -> str:
    return re.sub(r'[^a-z0-9]', '', value.casefold())


def words_at(words: list, y: float, left: float, right: float) -> list:
    return sorted((word for word in words if abs(word[1] - y) < 2.5 and left <= word[0] < right),
                  key=lambda word: word[0])


def printed(words: list, y: float, left: float, right: float, *, minimum: float = 0) -> str:
    matches = words_at(words, y, left, right)
    if not matches or min(word[5] for word in matches) < minimum:
        raise ValueError(f'Unclear official result at y={y}, x={left}:{right}')
    return ' '.join(word[4] for word in matches)


def result_from_page(page: dict, record: dict) -> dict:
    code = record['code']
    if (record.get('number_of_seats') != 1 or record.get('status') != 'needs_review'
            or record.get('source_warning_code') != 'official_summary_turnout_only'
            or record.get('summary_result') is not None
            or record.get('summary_source_sha256') != SOURCE_SHA256
            or record.get('summary_ocr_sha256') != OCR_SHA256
            or record.get('summary_page') != page['page']):
        raise ValueError(f'Prior Arunachal record differs: {code}')
    original_shape = dict(record, votes_polled=None)
    summary = parse_arunachal_2014(page, original_shape)
    if (summary is None or summary['votes_polled'] != record['votes_polled']
            or record['summary_totals'] != {key: summary[key] for key in
                                            ('electors', 'votes_polled', 'valid_candidate_votes')}):
        raise ValueError(f'Official Arunachal summary identity or totals differ: {code}')
    words = page['words']
    labels = [printed(words, y, 29, 83) for y in (782, 794, 812)]
    if labels[0] != 'WINNER' or not labels[1].startswith('RUNNER-UP') or labels[2] != 'MARGIN':
        raise ValueError(f'Official result row labels differ: {code}: {labels}')
    parties = [printed(words, y, 96, 150) for y in (782, 794)]
    names = [printed(words, y, 209, 425) for y in (782, 794)]
    votes = [int(printed(words, y, 465, 520, minimum=85)) for y in (782, 794)]
    margin = int(printed(words, 812, 96, 145, minimum=85))
    candidates = sorted((candidate for candidate in record['candidates'] if not candidate['is_nota']),
                        key=lambda candidate: -candidate['votes'])
    if (len(candidates) < 2 or not 0 < votes[1] < votes[0] <= summary['valid_candidate_votes']
            or votes[0] - votes[1] != margin
            or [candidate['votes'] for candidate in candidates[:2]] != votes
            or [candidate['party_at_election'] for candidate in candidates[:2]] != parties):
        raise ValueError(f'Official Arunachal candidate votes, party or margin differ: {code}')
    for source, candidate in zip(names, candidates[:2]):
        if norm(source) == norm(candidate['candidate_name']):
            continue
        # The official summary reverses the runner's two name tokens at Itanagar.
        if (code != 13 or sorted(norm(token) for token in source.split())
                != sorted(norm(token) for token in candidate['candidate_name'].split())):
            raise ValueError(f'Official Arunachal candidate name differs: {code}: {source}')
    return {'winner': names[0], 'winner_party': parties[0], 'winner_votes': votes[0],
            'runner': names[1], 'runner_party': parties[1], 'runner_votes': votes[1],
            'margin': margin}


def build(root: Path) -> dict:
    exports = root / 'exports'
    output = exports / (NAME + '.zip')
    if output.exists() or output.with_suffix('.sha256').exists():
        raise FileExistsError(output)
    prior_path = exports / PRIOR_BUNDLE
    checksum = prior_path.with_suffix('.sha256').read_text(encoding='ascii').split()
    if checksum != [digest(prior_path.read_bytes()), prior_path.name]:
        raise ValueError('Prior turnout bundle checksum differs')
    with zipfile.ZipFile(prior_path) as outer:
        prior_audit = json.loads(outer.read('AUDIT.json'))
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            old_body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if (prior_audit['new_sha256'] != PRIOR_SHA256 or digest(old_body) != PRIOR_SHA256
            or prior_audit['source_sha256'] != SOURCE_SHA256
            or prior_audit['ocr_sha256'] != OCR_SHA256):
        raise ValueError('Prior turnout correction differs')
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    old = json.loads(old_body)
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if (old['kind'] != 'ac' or old['year'] != 2014 or len(old['records']) != 60
            or old['source_url'] != manifest['url'] or old['source_sha256'] != SOURCE_SHA256
            or digest((folder / old['source_file']).read_bytes()) != SOURCE_SHA256
            or digest((folder / old['ocr_file']).read_bytes()) != OCR_SHA256):
        raise ValueError('Official Arunachal source identity differs')
    ocr = json.loads((folder / old['ocr_file']).read_text(encoding='utf-8'))
    if ocr['source_sha256'] != SOURCE_SHA256 or len(ocr['pages']) != 83:
        raise ValueError('Preserved Arunachal OCR differs')
    revised = json.loads(old_body)
    results = []
    for record in revised['records']:
        if record.get('source_warning_code') != 'official_summary_turnout_only':
            continue
        code = record['code']
        result = result_from_page(ocr['pages'][code + 11], record)
        record['summary_result'] = result
        results.append({'code': code, 'name': record['name'], 'summary_page': record['summary_page'],
                        'winner': result['winner'], 'margin': result['margin']})
    if len(results) != 49 or {row['code'] for row in results} != set(prior_audit['recovered_codes']):
        raise ValueError('Arunachal contested-seat coverage differs')
    for before, after in zip(old['records'], revised['records']):
        changed = {key for key in set(before) | set(after) if before.get(key) != after.get(key)}
        if before['code'] != after['code'] or changed != ({'summary_result'} if before['code'] in prior_audit['recovered_codes'] else set()):
            raise ValueError(f'Unrelated Arunachal record changed: {before["code"]}')
    new_body = json.dumps(revised, ensure_ascii=False, indent=2).encode('utf-8')
    with tempfile.TemporaryDirectory(prefix='ac-arunachal-2014-', dir=exports) as temporary:
        staging = Path(temporary) / 'archive'
        packages = Path(temporary) / 'packages'
        packages.mkdir()
        snapshot = f'election-archive/{EDITION}/extraction-{PRIOR_SHA256}.json'
        revision = f'election-archive/{EDITION}/extraction.json'
        for relative, body in ((snapshot, old_body), (revision, new_body)):
            target = staging / relative
            target.parent.mkdir(parents=True, exist_ok=True)
            target.write_bytes(body)
        inner = []
        for kind, relative in (('snapshot', snapshot), ('correction', revision)):
            bucket = hashlib.sha256(relative.encode('utf-8')).digest()[0] % 8
            path = packages / f'{kind}-{EDITION}.zip'
            package(staging, path, 'election-archive', bucket, 8, [relative],
                    PRIOR_SHA256 if kind == 'correction' else None,
                    snapshot if kind == 'correction' else None)
            inner.append(path)
        partial = output.with_suffix('.zip.partial')
        with zipfile.ZipFile(partial, 'w', compression=zipfile.ZIP_STORED) as zipped:
            for path in inner:
                zipped.write(path, path.name)
            zipped.writestr('SHA256SUMS', ''.join(f'{digest(path.read_bytes())}  {path.name}\n' for path in inner))
            zipped.writestr('ARCHIVES', EDITION + '\n')
            zipped.writestr('AUDIT.json', json.dumps({
                'scope': 'Arunachal 2014 AC official summary result declarations; detailed warnings retained',
                'edition': EDITION, 'source_url': old['source_url'], 'source_file': old['source_file'],
                'source_sha256': SOURCE_SHA256, 'ocr_sha256': OCR_SHA256,
                'previous_sha256': PRIOR_SHA256, 'new_sha256': digest(new_body),
                'results': results, 'uncontested_or_without_turnout': prior_audit['unresolved_codes'],
            }, indent=2))
            zipped.writestr('IMPORT.sh', import_script([EDITION]))
        partial.replace(output)
    output.with_suffix('.sha256').write_text(f'{digest(output.read_bytes())}  {output.name}\n', encoding='ascii')
    return {'bundle': str(output), 'results': len(results), 'previous_sha256': PRIOR_SHA256,
            'new_sha256': digest(new_body), 'sha256': digest(output.read_bytes())}


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
