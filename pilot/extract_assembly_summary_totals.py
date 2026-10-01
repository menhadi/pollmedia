"""Read explicitly labelled constituency totals from official Assembly PDF summaries."""

import re

import fitz


SUMMARY = re.compile(r'CONSTITUENCY DATA\s*-\s*SUMMARY', re.I)
IDENTITY = re.compile(r'CONSTITUENCY\s*:?\s*(\d+)\s*-\s*([^\n]+)', re.I)
ELECTORS = re.compile(r'II\.\s*ELECTORS\b(.*?)III\.\s*VOTERS\b', re.I | re.S)
VOTERS = re.compile(r'III\.\s*VOTERS\b(.*?)(?:III\s*\(?A\)?\s*\.|IV\.\s*VOTES\b)', re.I | re.S)
VOTES = re.compile(r'IV\.\s*VOTES\b(.*?)V\.\s*POLLING STATIONS\b', re.I | re.S)
TOTAL_ELECTORS = re.compile(r'^\s*\d+\.\s*TOTAL\s+([\d\s]+)$', re.I | re.M)
TOTAL_VOTERS = re.compile(r'^\s*\d+\.\s*TOTAL\s+([\d\s]+)$', re.I | re.M)
VALID_VOTES = re.compile(r'^\s*\d+\.\s*TOTAL\s*VALID VOTES POLLED\s+(\d+)\s*$', re.I | re.M)
NOTA_VOTES = re.compile(r'''^\s*\d+\.\s*VOTES POLLED FOR ['"]?NOTA['"]? \(INCLUDING POSTAL\)\s+(\d+)\s*$''', re.I | re.M)
POLL_PERCENT = re.compile(r'III\s*\(?A\)?\.\s*POLLING PERCENTAGE\s+([\d.]+)', re.I)


def parse_summary_page(text, page_number):
    """Return only totals whose labels and polling percentage agree on one source page."""
    if not SUMMARY.search(text):
        return None
    identity = IDENTITY.search(text)
    electors = ELECTORS.search(text)
    voters = VOTERS.search(text)
    votes = VOTES.search(text)
    if not all((identity, electors, voters, votes)):
        return None
    elector_totals = list(TOTAL_ELECTORS.finditer(electors[1]))
    voter_totals = list(TOTAL_VOTERS.finditer(voters[1]))
    valid_totals = list(VALID_VOTES.finditer(votes[1]))
    if not all(len(matches) == 1 for matches in (elector_totals, voter_totals, valid_totals)):
        return None
    elector_columns = [int(value) for value in elector_totals[0][1].split()]
    if not 1 <= len(elector_columns) <= 4:
        return None
    total_electors = elector_columns[-1]
    voter_columns = [int(value) for value in voter_totals[0][1].split()]
    if not 1 <= len(voter_columns) <= 4:
        return None
    total_voters = voter_columns[-1]
    total_valid = int(valid_totals[0][1])
    if not 0 < total_valid <= total_voters <= total_electors:
        return None
    percentage = POLL_PERCENT.search(text)
    if percentage and abs(float(percentage[1]) - 100 * total_voters / total_electors) > 0.06:
        return None
    summary = {
        'code': int(identity[1]),
        'name': identity[2].strip(),
        'electors': total_electors,
        'votes_polled': total_voters,
        'valid_candidate_votes': total_valid,
        'summary_page': page_number,
    }
    nota = list(NOTA_VOTES.finditer(votes[1]))
    if len(nota) > 1:
        return None
    if nota:
        summary['nota_votes'] = int(nota[0][1])
        if summary['valid_candidate_votes'] + summary['nota_votes'] > total_voters:
            return None
    return summary


def read_summary_pages(path):
    """Keep one unambiguous official summary page per constituency code."""
    found = {}
    duplicates = set()
    with fitz.open(path) as document:
        for index, page in enumerate(document):
            text = page.get_text()
            if not SUMMARY.search(text):
                if found and re.search(r'^\s*DETAILED RESULTS\b', text, re.I | re.M):
                    break
                continue
            summary = parse_summary_page(page.get_text(sort=True), index + 1)
            if summary is None:
                continue
            code = summary['code']
            if code in found:
                duplicates.add(code)
            else:
                found[code] = summary
    for code in duplicates:
        del found[code]
    return found


def corroborates(record, summary):
    """Check the summary against the preserved detailed candidate result."""
    if record.get('code') != summary['code']:
        return False
    source_name = re.sub(r'[^a-z0-9]', '', record.get('name', '').casefold())
    summary_name = re.sub(r'[^a-z0-9]', '', summary['name'].casefold())
    if not source_name or source_name != summary_name:
        return False
    for key in ('electors', 'votes_polled', 'valid_candidate_votes'):
        if record.get(key) is not None and record[key] != summary[key]:
            return False
    candidates = record.get('candidates') or []
    if not candidates or any(not isinstance(candidate.get('votes'), int) for candidate in candidates):
        return False
    nota = [candidate for candidate in candidates if candidate.get('is_nota') is True]
    if 'nota_votes' in summary:
        return (len(nota) == 1 and nota[0]['votes'] == summary['nota_votes']
                and sum(candidate['votes'] for candidate in candidates if candidate.get('is_nota') is not True)
                == summary['valid_candidate_votes'])
    return not nota and sum(candidate['votes'] for candidate in candidates) == summary['valid_candidate_votes']
