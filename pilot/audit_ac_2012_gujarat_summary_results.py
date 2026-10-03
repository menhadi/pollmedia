"""Audit the Gujarat 2012 AC summaries against preserved, coordinate-level OCR.

This is evidence classification only. It never edits an archived extraction.
"""

from collections import Counter
from difflib import SequenceMatcher
import hashlib
import io
import json
from pathlib import Path
import re
import zipfile


EDITION = '503135d3e838d38c93d3bce7'
PRIOR_BUNDLE = 'pollmedia-gujarat-2012-turnout-correction-20261001.zip'
PRIOR_SHA256 = '587b4dceb38eb1edba9c412092fc30a128222fca3405ba0e086f5f3e40e42a93'
SOURCE_SHA256 = '5c01eb6fdc9a01f445526932c0f5f4e4e3743b52fcf30f1b0854749c25fd4ee3'
WORDS_SHA256 = '06232b79d125d89b4d5725f241ce8dd42326da5c77309173ecba29b4b01988c3'
CELLS_SHA256 = '2008426ab8f91e2644d3aec831dc1f9c969b7951ef579b9de4660cd81870f397'
NUMBERS_SHA256 = '5820413cd6a29c992b43b2a122f0cd30ab4e791c928e79dd039c4249f23d0b3b'
ROOT = Path(__file__).resolve().parents[1]


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def normal(value: str) -> str:
    return re.sub(r'[^a-z0-9]', '', re.sub(r'\((?:SC|ST)\)', '', value, flags=re.I).casefold())


def same_name(source: str, detail: str) -> bool:
    # The official summary sometimes prints the same name tokens in a different order.
    source_tokens = sorted(normal(token) for token in source.split())
    detail_tokens = sorted(normal(token) for token in detail.split())
    return (SequenceMatcher(None, normal(source), normal(detail)).ratio() >= 0.82
            or SequenceMatcher(None, ''.join(source_tokens), ''.join(detail_tokens)).ratio() >= 0.91)


def same_party(source: str, detail: str) -> bool:
    source, detail = normal(source), normal(detail)
    if source == detail:
        return True
    # This exact BJP glyph is often read as U/N instead of J in the saved scan.
    return detail == 'bjp' and source in ('bup', 'bnp')


def load(root: Path = ROOT) -> tuple[dict, list, list, dict, bytes]:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    source = folder / f'{EDITION}-9045.pdf'
    manifest = json.loads((folder / 'manifest.json').read_text(encoding='utf-8'))
    if digest(source.read_bytes()) != SOURCE_SHA256 or manifest['url'] != 'https://old.eci.gov.in/files/file/3840-gujarat-2012/':
        raise ValueError('Official Gujarat PDF identity differs')
    evidence = []
    for name, expected in (('summary-result-ocr-words-v1.json', WORDS_SHA256),
                           ('summary-result-ocr-cells-v1.json', CELLS_SHA256),
                           ('summary-result-ocr-numbers-v1.json', NUMBERS_SHA256)):
        body = (folder / name).read_bytes()
        if digest(body) != expected:
            raise ValueError(f'Saved OCR checksum differs: {name}')
        value = json.loads(body)
        if value['source_sha256'] != SOURCE_SHA256:
            raise ValueError(f'Saved OCR source differs: {name}')
        evidence.append(value)
    prior = root / 'exports' / PRIOR_BUNDLE
    check = prior.with_suffix('.sha256').read_text(encoding='ascii').split()
    if check != [digest(prior.read_bytes()), prior.name]:
        raise ValueError('Prior turnout bundle checksum differs')
    with zipfile.ZipFile(prior) as outer:
        if json.loads(outer.read('AUDIT.json'))['new_sha256'] != PRIOR_SHA256:
            raise ValueError('Prior turnout revision differs')
        with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
            prior_body = inner.read(f'election-archive/{EDITION}/extraction.json')
    if digest(prior_body) != PRIOR_SHA256:
        raise ValueError('Prior archived JSON checksum differs')
    extraction = json.loads(prior_body)
    if (extraction['source_url'] != manifest['url'] or extraction['source_sha256'] != SOURCE_SHA256
            or extraction['year'] != 2012 or extraction['kind'] != 'ac' or len(extraction['records']) != 182):
        raise ValueError('Prior Gujarat extraction identity differs')
    words, cells, numbers = evidence
    if ([p['code'] for p in words['pages']] != list(range(1, 183))
            or [p['page'] for p in words['pages']] != list(range(22, 204))
            or [p['code'] for p in numbers['pages']] != list(range(1, 183))):
        raise ValueError('Summary page mapping differs')
    return extraction, words['pages'], cells['cells'], numbers['pages'], prior_body


def at(words: list, y: float, left: float, right: float, pattern: str, minimum: float = 85) -> int | None:
    candidates = [int(w[4]) for w in words if abs(w[1] - y) <= 3.5 and left <= w[0] < right
                  and w[5] >= minimum and re.fullmatch(pattern, w[4])]
    return candidates[0] if len(candidates) == 1 else None


def summary_field(page: dict, companions: dict, field: str, y: int) -> int | None:
    value = at(page['words'], y, 490, 545, r'\d{3,7}')
    if value is not None:
        return value
    companion = companions.get((page['code'], field))
    if companion is None:
        return None
    cells = [int(w['text']) for w in companion['words'] if w['confidence'] >= 85
             and re.fullmatch(r'\d{3,7}', w['text'])]
    return cells[0] if len(cells) == 1 else None


def heading_matches(page: dict, record: dict) -> bool:
    if ('CONSTITUENCY DATA - SUMMARY' not in page['text'].upper()
            or page['code'] != record['code'] or page['page'] != record['code'] + 21):
        return False
    headings = [line for line in page['text'].splitlines()[:8] if 'CONSTITUENCY' in line.upper() and '-' in line]
    for heading in headings:
        tail = heading.split('-', 1)[-1].strip()
        if normal(tail) == normal(record['name']):
            return True
    return False


def classify_totals(page: dict, companion: dict, record: dict) -> tuple[dict | None, str]:
    if not heading_matches(page, record) or record.get('number_of_seats') != 1:
        return None, 'identity_or_multiseat'
    electors = summary_field(page, companion, 'electors', 288)
    voters = summary_field(page, companion, 'voters', 386)
    valid = summary_field(page, companion, 'valid_candidate_votes', 478)
    if None in (electors, voters, valid) or not 0 < valid <= voters <= electors:
        return None, 'missing_or_invalid_source_totals'
    percent = [float(w[4]) for w in page['words'] if abs(w[1] - 410) <= 3.5
               and 195 <= w[0] < 250 and w[5] >= 85 and re.fullmatch(r'\d{1,3}\.\d{2}', w[4])]
    percent_agrees = len(percent) == 1 and abs(100 * voters / electors - percent[0]) <= 0.011
    general = at(page['words'], 334, 490, 545, r'\d{3,7}')
    postal = at(page['words'], 368, 490, 545, r'\d{1,7}')
    invalid = at(page['words'], 444, 490, 545, r'\d{1,7}')
    component_agrees = (general is not None and postal is not None and invalid is not None
                        and general + postal == voters and valid + invalid == voters)
    if not percent_agrees and not component_agrees:
        return None, 'percentage_and_components_unconfirmed'
    detail_electors = record.get('electors')
    if detail_electors is not None and abs(detail_electors - electors) * 10000 > electors * 5:
        return None, 'detail_elector_difference_too_large'
    return {'electors': electors, 'votes_polled': voters, 'valid_candidate_votes': valid,
            'source_page': page['page'], 'percent_agrees': percent_agrees,
            'components_agree': component_agrees}, 'source_totals_verified'


def result_number(cell: dict) -> int | None:
    matches = [int(w['text']) for w in cell['words'] if w['confidence'] >= 85
               and re.fullmatch(r'\d{2,7}', w['text'])]
    return matches[0] if len(matches) == 1 else None


def printed_result_numbers(page: dict) -> dict | None:
    values = {}
    for field, label, left, right in (('winner_votes', 'WINNER', 445, 515),
                                      ('runner_votes', 'RUNNER-UP', 445, 515),
                                      ('margin', 'MARGIN', 93, 165)):
        anchors = [w for w in page['words'] if w[0] < 95 and w[1] > 660 and w[4].upper().startswith(label)]
        if len(anchors) != 1:
            return None
        if field == 'margin':
            matches = [int(w[4]) for w in page['words'] if abs(w[1] - anchors[0][1]) <= 7
                       and left <= w[0] < right and w[5] >= 85 and re.fullmatch(r'\d{2,7}', w[4])]
            value = matches[0] if len(matches) == 1 else None
        else:
            value = at(page['words'], anchors[0][1], left, right, r'\d{2,7}', 85)
        if value is None:
            return None
        values[field] = value
    return values


def source_result(page: dict, numbers: dict, record: dict, totals: dict,
                  *, allow_firstpass: bool = False) -> tuple[dict | None, str]:
    if numbers['page'] != page['page'] or numbers['code'] != page['code']:
        return None, 'result_page_mismatch'
    rows = {cell['field']: result_number(cell) for cell in numbers['cells']}
    if allow_firstpass and (None in rows.values()
                            or rows['winner_votes'] - rows['runner_votes'] != rows['margin']):
        fallback = printed_result_numbers(page)
        if (fallback is not None and fallback['winner_votes'] > fallback['runner_votes']
                and fallback['winner_votes'] - fallback['runner_votes'] == fallback['margin']):
            rows = fallback
    if any(rows.get(key) is None for key in ('winner_votes', 'runner_votes', 'margin')):
        return None, 'unclear_result_number'
    if not 0 < rows['runner_votes'] < rows['winner_votes'] <= totals['valid_candidate_votes']:
        return None, 'result_number_out_of_range'
    if rows['winner_votes'] - rows['runner_votes'] != rows['margin']:
        return None, 'result_margin_arithmetic'
    source_candidates = []
    for label in ('WINNER', 'RUNNER-UP'):
        anchors = [w for w in page['words'] if w[0] < 95 and w[1] > 660 and w[4].upper().startswith(label)]
        if len(anchors) != 1:
            return None, 'unclear_result_label'
        y = anchors[0][1]
        parties = sorted([w for w in page['words'] if abs(w[1] - y) < 3.5 and 93 <= w[0] < 150], key=lambda w: w[0])
        names = sorted([w for w in page['words'] if abs(w[1] - y) < 3.5 and 200 <= w[0] < 435], key=lambda w: w[0])
        if len(parties) != 1 or not names:
            return None, 'unclear_result_name_or_party'
        source_candidates.append((parties[0][4].strip('.'), ' '.join(w[4] for w in names)))
    candidates = sorted((c for c in record['candidates'] if not c['is_nota'] and isinstance(c.get('votes'), int)),
                        key=lambda c: -c['votes'])
    if len(candidates) < 2 or [c['votes'] for c in candidates[:2]] != [rows['winner_votes'], rows['runner_votes']]:
        return None, 'detail_candidate_vote_difference'
    for (party, name), candidate in zip(source_candidates, candidates[:2]):
        if (not same_party(party, candidate['party_at_election'])
                or not same_name(name, candidate['candidate_name'])):
            return None, 'detail_candidate_name_or_party_difference'
    return {'winner': candidates[0]['candidate_name'], 'winner_party': candidates[0]['party_at_election'],
            'winner_votes': rows['winner_votes'], 'runner': candidates[1]['candidate_name'],
            'runner_party': candidates[1]['party_at_election'], 'runner_votes': rows['runner_votes'],
            'margin': rows['margin']}, 'source_result_verified'


def audit(root: Path = ROOT) -> dict:
    extraction, pages, cells, numbers, prior = load(root)
    companion = {(cell['code'], cell['field']): cell for cell in cells}
    reasons = Counter()
    results = []
    for record, page, number in zip(extraction['records'], pages, numbers):
        totals, reason = classify_totals(page, companion, record)
        reasons[reason] += 1
        result = None
        result_reason = 'turnout_unresolved'
        if totals is not None:
            result, result_reason = source_result(page, number, record, totals)
            reasons[result_reason] += 1
        results.append({'code': record['code'], 'name': record['name'], 'totals': totals,
                        'totals_reason': reason, 'result': result, 'result_reason': result_reason})
    return {'source_sha256': SOURCE_SHA256, 'prior_sha256': digest(prior),
            'coverage': dict(reasons), 'rows': results}


def classify_totals_refined(page: dict, companion: dict, record: dict, *, shifted_pages: bool = False) -> tuple[dict | None, str]:
    """Recover a low-confidence printed total only through independent source arithmetic."""
    existing, reason = classify_totals(page, companion, record)
    if existing is not None:
        return existing, reason
    if record.get('number_of_seats') != 1 or page['code'] != record['code'] or page['page'] != record['code'] + 21:
        return None, reason
    if not heading_matches(page, record):
        # One scanned heading omits the hyphen; both its code and name are legible.
        heading = [line for line in page['text'].splitlines()[:8] if 'CONSTITUENCY:' in line.upper()]
        if not (record['code'] == 42 and len(heading) == 1
                and re.search(r'CONSTITUENCY:\s*42\s+Vejalpur\s*$', heading[0], re.I)):
            return None, reason
    # Pages 39 and 40 have a four-point vertical offset in the same printed rows.
    shift = -4 if shifted_pages and page['code'] in (39, 40) else 0
    electors = summary_field(page, companion, 'electors', 288 + shift)
    if electors is None or electors < 10000:
        electors = at(page['words'], 288 + shift, 490, 545, r'\d{3,7}', 70)
        if electors is None or electors != record.get('electors'):
            return None, reason
    valid = summary_field(page, companion, 'valid_candidate_votes', 478 + shift)
    if valid is None:
        return None, reason
    voters = summary_field(page, companion, 'voters', 386 + shift)
    general = at(page['words'], 334 + shift, 490, 545, r'\d{3,7}', 70)
    postal = at(page['words'], 368 + shift, 490, 545, r'\d{1,7}', 70)
    invalid = at(page['words'], 444 + shift, 490, 545, r'\d{1,7}', 70)
    general_sum = general + postal if general is not None and postal is not None else None
    valid_sum = valid + invalid if invalid is not None else None
    if voters is None:
        low_confidence_voters = at(page['words'], 386 + shift, 490, 545, r'\d{3,7}', 70)
        if general_sum is not None and valid_sum == general_sum:
            voters = general_sum
        elif low_confidence_voters is not None and low_confidence_voters in (general_sum, valid_sum):
            voters = low_confidence_voters
    if voters is None or not 0 < valid <= voters <= electors:
        return None, reason
    percent = [float(w[4]) for w in page['words'] if abs(w[1] - (410 + shift)) <= 3.5
               and 195 <= w[0] < 250 and w[5] >= 70 and re.fullmatch(r'\d{1,3}\.\d{2}', w[4])]
    percent_agrees = len(percent) == 1 and abs(100 * voters / electors - percent[0]) <= 0.011
    component_agrees = general_sum == voters and valid_sum == voters
    if not percent_agrees or not (component_agrees or general_sum == voters or valid_sum == voters):
        return None, reason
    old_electors = record.get('electors')
    if old_electors not in (None, electors):
        minor_difference = abs(old_electors - electors) * 10000 <= electors * 5
        obvious_bad_detail = old_electors <= 10 and electors > 10000 and component_agrees
        if not (minor_difference or obvious_bad_detail):
            return None, reason
    return {'electors': electors, 'votes_polled': voters, 'valid_candidate_votes': valid,
            'source_page': page['page'], 'percent_agrees': True,
            'components_agree': component_agrees,
            'arithmetic_recovery': True}, 'source_totals_verified_by_arithmetic'


def audit_refined(root: Path = ROOT, *, shifted_pages: bool = False,
                  result_fallback: bool = False) -> dict:
    extraction, pages, cells, numbers, prior = load(root)
    companion = {(cell['code'], cell['field']): cell for cell in cells}
    reasons = Counter()
    results = []
    for record, page, number in zip(extraction['records'], pages, numbers):
        totals, reason = classify_totals_refined(page, companion, record, shifted_pages=shifted_pages)
        reasons[reason] += 1
        result = None
        result_reason = 'turnout_unresolved'
        if totals is not None:
            result, result_reason = source_result(page, number, record, totals,
                                                  allow_firstpass=result_fallback)
            reasons[result_reason] += 1
        results.append({'code': record['code'], 'name': record['name'], 'totals': totals,
                        'totals_reason': reason, 'result': result, 'result_reason': result_reason})
    return {'source_sha256': SOURCE_SHA256, 'prior_sha256': digest(prior),
            'coverage': dict(reasons), 'rows': results}


if __name__ == '__main__':
    result = audit()
    print(json.dumps({'source_sha256': result['source_sha256'], 'prior_sha256': result['prior_sha256'],
                      'coverage': result['coverage'],
                      'unresolved_codes': [r['code'] for r in result['rows'] if r['totals'] is None]}, indent=2))
