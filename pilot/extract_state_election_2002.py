"""2002 UP PDF adapter; preserves absent vote components and incomplete pages."""
import argparse
import json
import re

import fitz
from extract_state_election_2007 import save


def summary_number(page, label, left, right, minimum_y=0, maximum_y=1000, optional=False):
    words = page.get_text('words')
    labels = [w for w in words if w[4] == label and 100 < w[0] < 160 and minimum_y < w[1] < maximum_y]
    if len(labels) != 1:
        raise ValueError('Ambiguous summary label: ' + label)
    cells = [w[4] for w in words if left < w[0] < right and abs(w[1] - labels[0][1]) < 2 and w[4].isdigit()]
    if not cells and optional:
        return None
    if len(cells) != 1:
        raise ValueError('Ambiguous summary number: ' + label)
    return int(cells[0])


def extract(path, year=2002):
    editions = {2002: (403, [], 136), 1996: (425, [385], 121), 1993: (425, [233, 279, 394], 216), 1991: (425, [383, 393, 394, 396, 397, 398], 179), 1989: (425, [], 145), 1985: (425, [], 144), 1980: (425, [], 116), 1977: (425, [], 84), 1974: (425, [207], 104), 1969: (425, [], 81), 1967: (425, [], 84), 1962: (430, [], 84), 1957: (341, [], 62), 1951: (347, [], 72)}
    if year not in editions:
        raise ValueError("Unsupported report edition")
    maximum_code, absent_codes, expected_pages = editions[year]
    expected_codes = [code for code in range(1, maximum_code + 1) if code not in absent_codes]
    summaries, chunks, pages, report_pages = {}, [], {}, []
    with fitz.open(path) as doc:
        for index, page in enumerate(doc):
            text = page.get_text()
            if 'CONSTITUENCY DATA - SUMMARY' in text:
                match = re.search(r'CONSTITUENCY\s*:\s*(\d+)\s*-\s*([^\n]+)', text)
                if not match and year == 1991 and 'MARGIN' in text and 'CONSTITUENCY :' not in text:
                    continue
                if not match or int(match[1]) in summaries:
                    raise ValueError('Summary identity missing or duplicated')
                summaries[int(match[1])] = (index + 1, match[2].strip(), page)
            footer = re.search(rf'rptDetailedResults - Page (\d+) of\s+{expected_pages}\b', text)
            if not footer:
                continue
            report_pages.append(int(footer[1]))
            text = text[:footer.start()]
            lines = [line.strip() for line in text.splitlines() if line.strip() and line.strip() not in {'DETAILED RESULTS', 'No.', 'CANDIDATE', 'SEX', 'PARTY', 'VOTES', '%'} and not line.startswith('Election Commission of India')]
            cleaned = '\n'.join(lines)
            cleaned = re.sub(r'[ \t]+NUMBER OF SEATS', '\nNUMBER OF SEATS', cleaned)
            if year == 1991:
                cleaned = cleaned.replace('Constituency :\n1 .\n290 . KANPUR CANTONMENT\n', 'Constituency :\n290 . KANPUR CANTONMENT\n1 . ')
            if year in [1989, 1962, 1957, 1951]:
                cleaned = re.sub(r'Constituency\s*:?\s*(\d+)[ \n]+([^\n]+)', r'Constituency :\n\1 . \2', cleaned)
            for match in re.finditer(r'Constituency\s*:\s*(\d+)\s*\.', cleaned):
                pages[int(match[1])] = index + 1
            chunks.append(cleaned)
        allowed_pages = [list(range(1, expected_pages + 1))]
        if year == 2002:
            allowed_pages.append(list(range(1, expected_pages)))
        if report_pages not in allowed_pages:
            raise ValueError('Unexpected gaps or order in detailed report pages')
        text = '\n'.join(chunks)
        identities = list(re.finditer(r'Constituency\s*:\s*(\d+)\s*\.\s*([^\n]+)\n', text))
        if [int(m[1]) for m in identities] != expected_codes or sorted(summaries) != expected_codes:
            raise ValueError(f'Expected {len(expected_codes)} historical identities and summaries for {year}')
        records = []
        for i, identity in enumerate(identities):
            code, name = int(identity[1]), identity[2].strip()
            summary_page, summary_name, summary = summaries[code]
            record = dict(code=code, name=name, detail_page=pages[code], summary_page=summary_page, candidates=[])
            records.append(record)
            try:
                body = text[identity.end():identities[i + 1].start() if i + 1 < len(identities) else len(text)].strip()
                seats = re.match(r'NUMBER OF SEATS\s+(\d+)\s+', body)
                record['number_of_seats'] = int(seats[1]) if seats else 1
                if seats:
                    body = body[seats.end():]
                total = re.search(r'ELECTORS\s*:\s*(\d+)\s*([\d.]+)%\s*VALID VOTES\s*:?\s*(\d+)\s*VOTERS\s*:\s*(\d+)\s*POLL PERCENTAGE\s*:\s*$', body)
                if year == 1991:
                    old_total = re.search(r'ELECTORS\s*:\s*(\d+)\s*VOTERS\s*:\s*(\d+)\s*POLL PERCENTAGE\s*:\s*([\d.]+)%\s*VALID VOTES\s*:\s*(\d+)\s*$', body)
                    if old_total:
                        body = body[:old_total.start()] + f'ELECTORS :\n{old_total[1]}\n{old_total[3]}%\nVALID VOTES :\n{old_total[4]}\nVOTERS :\n{old_total[2]}\nPOLL PERCENTAGE :'
                        total = re.search(r'ELECTORS\s*:\s*(\d+)\s*([\d.]+)%\s*VALID VOTES\s*:\s*(\d+)\s*VOTERS\s*:\s*(\d+)\s*POLL PERCENTAGE\s*:\s*$', body)
                rows = body[:total.start()].strip() if total else body
                if year == 1991:
                    rows = re.sub(r'(?m)^(\d+)\s*\.\s+([\s\S]+?)\n([MF])\n([^\n]+)\n(\d+)\n([\d.]+)%', lambda m: f'. {m[2]}\n{m[3]}\n{m[4]}\n{m[5]}\n{m[6]}%\n{m[1]}', rows)
                candidates = []
                record['candidates'] = candidates
                if 'Uncontested' in rows:
                    uncontested = re.match(r'\.\s+(.+?)\n([MF])\n([^\n]+)\n(\d+)\nUncontested', rows, re.S)
                    if uncontested:
                        candidates.append(dict(candidate_name=' '.join(uncontested[1].split()), sex=uncontested[2], party_at_election=uncontested[3], votes=None, general_votes=None, postal_votes=None, source_row=int(uncontested[4])))
                    raise ValueError('Uncontested result: no vote total reported; separate publication review required')
                for block in re.split(r'(?m)^\.\s+', rows):
                    if not block.strip():
                        continue
                    match = re.fullmatch(r'(.+?)\n([MF])\n(.+?)\n(\d+)\n([\d.]+%|#Num!)\n(\d+)\s*', block, re.S)
                    if not match:
                        raise ValueError('Candidate row layout not fully parsed')
                    candidates.append(dict(candidate_name=' '.join(match[1].split()), sex=match[2], party_at_election=''.join(match[3].splitlines()), votes=int(match[4]), reported_vote_percent=None if match[5] == '#Num!' else float(match[5].rstrip('%')), source_row=int(match[6]), general_votes=None, postal_votes=None))
                record['candidates'] = candidates
                if not total:
                    raise ValueError('Detailed constituency totals missing; archived report ends before the final detailed page 136')
                record.update(electors=int(total[1]), valid_candidate_votes=int(total[3]), votes_polled=int(total[4]))
                if candidates and all(c['votes'] == 0 for c in candidates):
                    raise ValueError('Report gives zero votes and no usable vote percentage; uncontested status requires source review')
                if record['number_of_seats'] > 1:
                    raise ValueError('Multi-member constituency: candidate data extracted; winner and reserved-seat allocation require a separate validation method')
                normal = lambda value: re.sub(r'[^a-z0-9]', '', value.lower())
                if normal(name) != normal(summary_name):
                    raise ValueError('Summary and detailed identities differ')
                if sorted(c['source_row'] for c in candidates) != list(range(1, len(candidates) + 1)):
                    raise ValueError('Candidate sequence incomplete or duplicated')
                reported_count = summary_number(summary, 'CONTESTED', 450, 510, optional=year <= 1977) if year >= 1967 else None
                record['reported_candidate_count'] = reported_count
                if reported_count is not None and len(candidates) != reported_count:
                    raise ValueError('Candidate count differs from summary')
                summary_values = dict(electors=summary_number(summary, 'TOTAL', 450, 510, 280, 350), votes_polled=summary_number(summary, 'POLLED', 260, 320), valid_candidate_votes=summary_number(summary, 'VALID', 260, 320))
                record['summary_totals'] = summary_values
                if any(record[key] != value for key, value in summary_values.items()):
                    raise ValueError('Summary and detailed totals differ: ' + '; '.join(f'{key}: detail {record[key]}, summary {value}' for key, value in summary_values.items() if record[key] != value))
                candidate_sum = sum(c['votes'] for c in candidates)
                if candidate_sum != record['valid_candidate_votes']:
                    raise ValueError(f'Candidate sum {candidate_sum}; reported valid votes {record["valid_candidate_votes"]}. Totals differ')
                if not 0 < candidate_sum <= record['votes_polled'] <= record['electors']:
                    raise ValueError('Electorate and voter totals are inconsistent')
                ranked = sorted(candidates, key=lambda c: c['votes'], reverse=True)
                if len(ranked) < 2 or ranked[0]['votes'] == ranked[1]['votes']:
                    raise ValueError('Winner requires review')
                record.update(status='validated', winner=ranked[0]['candidate_name'], margin=ranked[0]['votes'] - ranked[1]['votes'])
            except ValueError as error:
                record.update(status='needs_review', error=str(error))
    return records


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('manifest')
    args = parser.parse_args()
    result = save(args.manifest, year=2002, extractor=extract)
    print(json.dumps({key: result[key] for key in ['year', 'validated_count', 'review_count']}))
