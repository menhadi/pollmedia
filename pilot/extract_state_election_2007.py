"""Extract the archived 2007 UP edition without assigning modern place identities."""
import argparse
import hashlib
import json
import re
from pathlib import Path

import fitz
from extract_state_election_2012 import number


def extract(path):
    records = []
    with fitz.open(path) as doc:
        summaries = {}
        for index, page in enumerate(doc):
            match = re.search(r'CONSTITUENCY DATA - SUMMARY\s+(\d+)\s+CONSTITUENCY\s+-\s*([^\n]+)', page.get_text())
            if match:
                code = int(match[1])
                if code in summaries:
                    raise ValueError('Duplicate summary identity')
                summaries[code] = (index + 1, match[2].strip(), page)
        for index, page in enumerate(doc):
            text = page.get_text()
            if ' DETAILED RESULTS' not in text:
                continue
            identity = re.search(r'Uttar Pradesh\s+(\d+)\.\s+([^\n]+)\n', text)
            if not identity:
                raise ValueError('Unrecognized detailed page identity')
            code, name = int(identity[1]), identity[2].strip()
            record = dict(code=code, name=name, detail_page=index + 1, candidates=[])
            records.append(record)
            try:
                body = text[identity.end():].strip()
                total = re.search(r'TOTAL:\s+(\d+)\s+(\d+)\s+(\d+)\s+\d+\s*$', body)
                if not total:
                    raise ValueError('Detailed total layout changed')
                rows = body[:total.start()].strip()
                pattern = re.compile(r'\.\s+(.+?)\n([MF])\n(\d+)\n(\d+)\n([^\n]+(?:\n[^\d\n][^\n]*)*)\n(\d+)\n(GEN|SC|ST)\n(\d+)\n(\d+)(?:\n|$)', re.S)
                matches = list(pattern.finditer(rows))
                if ''.join(m[0] for m in matches).strip() != rows:
                    raise ValueError('Candidate layout was not fully parsed')
                candidates = [dict(candidate_name=' '.join(m[1].split()), sex=m[2], general_votes=int(m[3]), postal_votes=int(m[4]), party_at_election=''.join(m[5].splitlines()), age=int(m[6]), category=m[7], votes=int(m[8]), source_row=int(m[9])) for m in matches]
                record['candidates'] = candidates
                summary_page, summary_name, summary = summaries[code]
                record['summary_page'] = summary_page
                normal = lambda s: re.sub(r'[^a-z0-9]', '', s.lower())
                if normal(name) != normal(summary_name):
                    raise ValueError('Summary and detail names differ')
                record.update(electors=number(summary, '3. TOTAL'), votes_polled=number(summary, '4. TOTAL'), valid_candidate_votes=number(summary, '3. TOTAL VALID VOTES POLLED'))
                if not candidates or [c['source_row'] for c in candidates] != list(range(1, len(candidates) + 1)):
                    raise ValueError('Candidate sequence incomplete')
                if any(c['general_votes'] + c['postal_votes'] != c['votes'] for c in candidates):
                    raise ValueError('Candidate vote components differ from total')
                sums = [sum(c[key] for c in candidates) for key in ['general_votes', 'postal_votes', 'votes']]
                record['detailed_totals'] = dict(zip(['general_votes', 'postal_votes', 'votes'], [int(total[i]) for i in [1, 2, 3]]))
                if sums != [int(total[i]) for i in [1, 2, 3]] or sums[2] != record['valid_candidate_votes']:
                    raise ValueError(f'Candidate sum {sums[2]}; detailed total {total[3]}; summary valid votes {record["valid_candidate_votes"]}. Totals differ.')
                if not 0 < record['valid_candidate_votes'] <= record['votes_polled'] <= record['electors']:
                    raise ValueError('Elector and voter totals are inconsistent')
                ranked = sorted(candidates, key=lambda c: c['votes'], reverse=True)
                if len(ranked) < 2 or ranked[0]['votes'] == ranked[1]['votes']:
                    raise ValueError('Winner requires review')
                record.update(status='validated', winner=ranked[0]['candidate_name'], margin=ranked[0]['votes'] - ranked[1]['votes'])
            except (ValueError, KeyError) as error:
                record.update(status='needs_review', error=str(error))
    if [r['code'] for r in records] != list(range(1, 404)) or sorted(summaries) != list(range(1, 404)):
        raise ValueError('Expected 403 unique ordered detailed seats and summaries')
    return records


def save(manifest_path, year=2007, extractor=extract):
    manifest_path = Path(manifest_path)
    manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
    if manifest['kind'] != 'ac' or manifest['year'] != year or len(manifest['files']) != 1:
        raise ValueError(f'Expected the single-report UP Assembly {year} manifest')
    source = manifest['files'][0]
    if Path(source['file']).name != source['file']:
        raise ValueError('Invalid archive filename')
    path = manifest_path.parent / source['file']
    checksum = hashlib.sha256(path.read_bytes()).hexdigest()
    if checksum != source['sha256']:
        raise ValueError('Archived PDF checksum differs')
    records = extractor(path)
    result = dict(adapter=f'eci-up-{year}-v1', year=year, kind='ac', source_url=manifest['url'], source_file=source['file'], source_sha256=checksum, identity_scope=f'UP Assembly {year} source edition; historical mapping pending', records=records, validated_count=sum(r['status'] == 'validated' for r in records), review_count=sum(r['status'] != 'validated' for r in records))
    if year <= 1996:
        absent = {1996: [385], 1993: [233, 279, 394], 1991: [383, 393, 394, 396, 397, 398], 1974: [207]}.get(year, [])
        result['coverage_note'] = f'The {year} source contains {len(records)} constituencies in the then Uttar Pradesh territory. Historical boundaries must not be treated as present-day boundaries.'
        if absent:
            result['coverage_note'] += ' Codes ' + ', '.join(map(str, absent)) + ' are absent from both source sections; no results have been inferred for them.'
        if year in [1951, 1957]:
            result['coverage_note'] += ' This edition includes multi-member constituencies. Seat counts are preserved; winner allocation requires separate review.'
    destination = manifest_path.parent / 'extraction.json'
    temporary = destination.with_suffix('.tmp')
    temporary.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
    temporary.replace(destination)
    return result


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('manifest')
    args = parser.parse_args()
    result = save(args.manifest)
    print(json.dumps({key: result[key] for key in ['year', 'validated_count', 'review_count']}))
