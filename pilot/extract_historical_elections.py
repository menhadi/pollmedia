"""Dispatch verified UP archive manifests to supported historical PDF adapters."""
import argparse
import hashlib
import json
from pathlib import Path

from extract_state_election_2002 import extract as extract_legacy
from extract_state_election_2007 import extract as extract_2007, save

YEARS = [2022, 2017, 2012, 2007, 2002, 1996, 1993, 1991, 1989, 1985, 1980, 1977, 1974, 1969, 1967, 1962, 1957, 1951]


def modern_record(item):
    record = dict(code=item['code'], name=item['name'], candidates=[], status='needs_review')
    if 'payload' not in item:
        record['error'] = item.get('error', 'This record requires review.')
        return record
    record.update(item['payload'])
    candidates = record['candidates']
    contesting = [c for c in candidates if not c.get('is_nota') and c['party_at_election'].upper() != 'NOTA']
    if (not contesting or any(c['general_votes'] + c['postal_votes'] != c['votes'] for c in candidates)
            or sum(c['votes'] for c in contesting) != record['valid_candidate_votes']
            or not 0 < sum(c['votes'] for c in candidates) <= record['votes_polled'] <= record['electors']):
        record['error'] = 'Candidate components or report totals do not reconcile.'
        return record
    record['status'] = 'validated'
    ranked = sorted(contesting, key=lambda c: c['votes'], reverse=True)
    if len(ranked) > 1 and ranked[0]['votes'] > ranked[1]['votes']:
        record.update(winner=ranked[0]['candidate_name'], margin=ranked[0]['votes'] - ranked[1]['votes'])
    return record


def save_modern(manifest_path):
    manifest_path = Path(manifest_path)
    manifest = json.loads(manifest_path.read_text(encoding='utf-8'))
    year = manifest['year']
    if manifest['kind'] != 'ac' or year not in [2012, 2017, 2022]:
        raise ValueError('Unsupported modern UP Assembly manifest')
    names = {2012: ['2012.pdf'], 2017: ['Detailed Results.xlsx', 'Constituency Data Summry.xlsx'], 2022: ['10-Detailed Results.xlsx', '8-Constituency Data Summery Report.xlsx']}[year]
    sources, paths = [], []
    for name in names:
        matches = [f for f in manifest['files'] if f['name'] == name]
        if len(matches) != 1:
            raise ValueError('Required official source missing or ambiguous: ' + name)
        source = matches[0]
        if Path(source['file']).name != source['file']:
            raise ValueError('Invalid archive filename')
        path = manifest_path.parent / source['file']
        if hashlib.sha256(path.read_bytes()).hexdigest() != source['sha256']:
            raise ValueError('Archived source checksum differs')
        sources.append(source)
        paths.append(path)
    if year == 2012:
        from extract_state_election_2012 import extract_state
    elif year == 2017:
        from extract_state_election_2017 import extract_state
    else:
        from extract_state_election import extract_state
    records = [modern_record(item) for item in extract_state(*paths)]
    if sorted(r['code'] for r in records) != list(range(1, 404)):
        raise ValueError('Expected 403 unique Assembly constituency records')
    result = dict(adapter=f'eci-up-{year}-archive-v1', kind='ac', year=year,
                  source_url=manifest['url'], source_file=sources[0]['file'], source_sha256=sources[0]['sha256'],
                  additional_sources=sources[1:], records=records,
                  validated_count=sum(r['status'] == 'validated' for r in records),
                  review_count=sum(r['status'] != 'validated' for r in records))
    destination = manifest_path.parent / 'extraction.json'
    temporary = destination.with_suffix('.tmp')
    temporary.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
    temporary.replace(destination)
    return result


def run(manifest):
    year = json.loads(Path(manifest).read_text(encoding='utf-8'))['year']
    if year not in YEARS:
        raise ValueError('Unsupported historical edition')
    if year >= 2012:
        return save_modern(manifest)
    extractor = extract_2007 if year == 2007 else lambda path: extract_legacy(path, year=year)
    return save(manifest, year=year, extractor=extractor)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('manifest')
    args = parser.parse_args()
    result = run(args.manifest)
    print(json.dumps({key: result[key] for key in ['year', 'validated_count', 'review_count']}))
