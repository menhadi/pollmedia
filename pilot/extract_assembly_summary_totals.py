"""Read explicitly labelled constituency totals from official Assembly PDF summaries."""

import re

import fitz


SUMMARY = re.compile(r'CONSTITUENCY DATA\s*-\s*SUMMARY', re.I)
IDENTITY = re.compile(r'CONSTITUENCY\s*:?\s*(\d+)\s*-\s*([^\n]+)', re.I)
ELECTORS = re.compile(r'II\.\s*ELECTORS\b(.*?)III\.\s*VOTERS\b', re.I | re.S)
VOTERS = re.compile(r'III\.\s*VOTERS\b(.*?)III\s*\(?A\)?\s*\.', re.I | re.S)
VOTES = re.compile(r'IV\.\s*VOTES\b(.*?)V\.\s*POLLING STATIONS\b', re.I | re.S)
TOTAL_ELECTORS = re.compile(r'^\s*3\.\s*TOTAL\s+([\d\s]+)$', re.I | re.M)
TOTAL_VOTERS = re.compile(r'^\s*4\.\s*TOTAL\s+(\d+)\s*$', re.I | re.M)
VALID_VOTES = re.compile(r'^\s*3\.\s*TOTAL VALID VOTES POLLED\s+(\d+)\s*$', re.I | re.M)
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
    elector_total = TOTAL_ELECTORS.search(electors[1])
    voter_total = TOTAL_VOTERS.search(voters[1])
    valid_total = VALID_VOTES.search(votes[1])
    if not all((elector_total, voter_total, valid_total)):
        return None
    elector_columns = [int(value) for value in elector_total[1].split()]
    if not 1 <= len(elector_columns) <= 4:
        return None
    total_electors = elector_columns[-1]
    total_voters = int(voter_total[1])
    total_valid = int(valid_total[1])
    if not 0 < total_valid <= total_voters <= total_electors:
        return None
    percentage = POLL_PERCENT.search(text)
    if percentage and abs(float(percentage[1]) - 100 * total_voters / total_electors) > 0.06:
        return None
    return {
        'code': int(identity[1]),
        'name': identity[2].strip(),
        'electors': total_electors,
        'votes_polled': total_voters,
        'valid_candidate_votes': total_valid,
        'summary_page': page_number,
    }


def read_summary_pages(path):
    """Keep one unambiguous official summary page per constituency code."""
    found = {}
    duplicates = set()
    with fitz.open(path) as document:
        for index, page in enumerate(document):
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
    return sum(candidate['votes'] for candidate in candidates) == summary['valid_candidate_votes']
