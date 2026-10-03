"""Cross-check unresolved Gujarat 2012 declarations with independent OCR and detail."""

from difflib import SequenceMatcher
import hashlib
import json
from pathlib import Path
import re

from audit_ac_2012_gujarat_summary_results import (
    EDITION, ROOT, SOURCE_SHA256, WORDS_SHA256, audit_refined, load,
    normal, result_number, same_party,
)


STRIPS_SHA256 = '8123d370f84a23b225b5c375e90b41d7730806405eed1f25f8f8f3cbcf7feaf3'


def digest(body: bytes) -> str:
    return hashlib.sha256(body).hexdigest()


def source_row(page: dict, label: str, *, require_number: bool = True) -> dict | None:
    anchors = [w for w in page['words'] if w[0] < 95 and w[4].upper().startswith(label)]
    if len(anchors) != 1:
        return None
    y = anchors[0][1]
    words = sorted((w for w in page['words'] if abs(w[1] - y) < 4), key=lambda w: w[0])
    parties = [w for w in words if 93 <= w[0] < 160 and re.fullmatch(r'[A-Za-z.]+', w[4])]
    names = [w for w in words if 200 <= w[0] < 440 and re.search(r'[A-Za-z]', w[4])]
    left, right = (93, 165) if label == 'MARGIN' else (445, 515)
    votes = [w for w in words if left <= w[0] < right and re.fullmatch(r'\d{2,7}', w[4])]
    if require_number and (len(votes) != 1 or votes[0][5] < (85 if label == 'MARGIN' else 80)):
        return None
    return {'party': parties[0][4].strip('.') if len(parties) == 1 else '',
            'name': ' '.join(w[4] for w in names),
            'votes': int(votes[0][4]) if len(votes) == 1 else None,
            'vote_confidence': votes[0][5] if len(votes) == 1 else None,
            'name_words': names}


def detailed_candidate(record: dict, votes: int, party: str) -> dict | None:
    matches = [candidate for candidate in record['candidates'] if not candidate.get('is_nota')
               and candidate.get('votes') == votes
               and same_party(party, candidate.get('party_at_election') or '')]
    return matches[0] if len(matches) == 1 else None


def clear_source_name(high: str, low: str) -> bool:
    return bool(high and low and SequenceMatcher(None, normal(high), normal(low)).ratio() >= 0.92
                and not high.rstrip().endswith(('(', ':')))


def result_from_strip(high: dict, low: dict, numbers: dict, record: dict, totals: dict) -> tuple[dict | None, str]:
    if (high['source_sha256'] != SOURCE_SHA256 or high['code'] != low['code']
            or high['page'] != low['page'] or high['page'] != record['code'] + 21
            or numbers['page'] != high['page'] or record.get('number_of_seats') != 1):
        return None, 'source_identity'
    high_rows = {label: source_row(high, label) for label in ('WINNER', 'RUNNER-UP', 'MARGIN')}
    low_rows = {label: source_row(low, label, require_number=False) for label in ('WINNER', 'RUNNER-UP')}
    if any(row is None for row in high_rows.values()):
        return None, 'unclear_strip_numbers'
    winner, runner, margin = (high_rows[label]['votes'] for label in ('WINNER', 'RUNNER-UP', 'MARGIN'))
    if not 0 < runner < winner <= totals['valid_candidate_votes'] or winner - runner != margin:
        return None, 'strip_result_arithmetic'
    cropped = {cell['field']: result_number(cell) for cell in numbers['cells']}
    if sum(cropped[field] == value for field, value in
           (('winner_votes', winner), ('runner_votes', runner), ('margin', margin))) < 2:
        return None, 'independent_number_disagreement'
    declarations = []
    detail_conflict = False
    for label in ('WINNER', 'RUNNER-UP'):
        source = high_rows[label]
        low_source = low_rows[label]
        if not source['party'] or not re.fullmatch(r'[A-Z]{2,6}', source['party']):
            return None, 'unclear_party'
        candidate = detailed_candidate(record, source['votes'], source['party'])
        if candidate is not None:
            name = candidate['candidate_name']
            party = candidate['party_at_election']
            if source['name'] and not (SequenceMatcher(None, normal(source['name']), normal(name)).ratio() >= 0.65
                                       or set(normal(token) for token in source['name'].split())
                                       & set(normal(token) for token in name.split())):
                return None, 'detail_name_disagrees'
        else:
            detail_conflict = True
            if (low_source is None or not (same_party(source['party'], low_source['party'])
                                           or same_party(low_source['party'], source['party']))
                    or not clear_source_name(source['name'], low_source['name'])):
                return None, 'uncorroborated_summary_name_or_party'
            name = source['name']
            party = source['party']
        declarations.append({'name': name, 'party': party, 'votes': source['votes']})
    return {'winner': declarations[0]['name'], 'winner_party': declarations[0]['party'],
            'winner_votes': winner, 'runner': declarations[1]['name'],
            'runner_party': declarations[1]['party'], 'runner_votes': runner,
            'margin': margin}, 'official_summary_with_detail_difference' if detail_conflict else 'official_summary_with_detail_match'


def audit(root: Path = ROOT) -> dict:
    folder = root / 'application/storage/app/private/election-archive' / EDITION
    body = (folder / 'summary-result-ocr-strips-v1.json').read_bytes()
    if digest(body) != STRIPS_SHA256:
        raise ValueError('Gujarat result strip OCR checksum differs')
    strips = json.loads(body)
    if strips['source_sha256'] != SOURCE_SHA256 or strips['base_ocr_sha256'] != WORDS_SHA256:
        raise ValueError('Gujarat result strip source differs')
    extraction, pages, _, numbers, _ = load(root)
    baseline = audit_refined(root, shifted_pages=True, result_fallback=True)
    targets = [row['code'] for row in baseline['rows'] if row['result'] is None]
    if [row['code'] for row in strips['pages']] != targets:
        raise ValueError('Gujarat result strip target order differs')
    verified = {}
    unresolved = {}
    for strip in strips['pages']:
        code = strip['code']
        record = extraction['records'][code - 1]
        totals = baseline['rows'][code - 1]['totals']
        result, reason = result_from_strip(strip, pages[code - 1], numbers[code - 1], record, totals)
        if result is not None:
            verified[code] = {'result': result, 'reason': reason, 'source_page': strip['page']}
        else:
            unresolved[code] = reason
    return {'source_sha256': SOURCE_SHA256, 'strip_ocr_sha256': STRIPS_SHA256,
            'verified': verified, 'unresolved': unresolved}


if __name__ == '__main__':
    result = audit()
    print(json.dumps({'verified': len(result['verified']),
                      'by_reason': {reason: sum(row['reason'] == reason for row in result['verified'].values())
                                    for reason in {row['reason'] for row in result['verified'].values()}},
                      'unresolved': result['unresolved']}, indent=2))
