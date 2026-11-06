"""Aggregate published village records by existing official LGD parent codes.

This derivative never edits source rows, directory identities or geometry.
Ambiguous Census identities and split current village crosswalks are withheld.
"""
import argparse
import collections
import hashlib
import json
import math
import sqlite3
from pathlib import Path


def number(value):
    if isinstance(value, bool):
        return None
    try:
        result = float(value)
        return result if math.isfinite(result) and result >= 0 else None
    except (TypeError, ValueError):
        return None


def summaries(records):
    population = households = numerator = denominator = 0
    counts = collections.Counter()
    for row in records:
        pop, hh, children, literate = map(number, row[6:10])
        counts['records'] += 1
        if pop is not None:
            population += pop
            counts['population'] += 1
        if hh is not None:
            households += hh
            counts['households'] += 1
        if pop is not None and children is not None and literate is not None and 0 <= literate <= pop - children and pop - children > 0:
            numerator += literate
            denominator += pop - children
            counts['literacy'] += 1
    return {'population': population if counts['population'] else None,
            'households': households if counts['households'] else None,
            'literacy': round(100 * numerator / denominator, 2) if denominator else None,
            'literate': numerator, 'population_7_plus': denominator, 'counts': dict(counts)}


def build(application, inventory, sources, output):
    directory = application / 'storage/app/electoral-villages/links.sqlite'
    db = sqlite3.connect(f'file:{directory.as_posix()}?mode=ro', uri=True)
    villages = [dict(zip(['lgd', 'state', 'district', 'subdistrict', 'census2011'], row)) for row in db.execute(
        'SELECT d.lgd,d.state,a.district,a.subdistrict,d.census2011 FROM directory d JOIN ancestry a ON a.lgd=d.lgd AND a.state=d.state')]
    db.close()
    census = collections.defaultdict(dict)
    with inventory.open(encoding='utf-8') as stream:
        for line in stream:
            row = json.loads(line)
            census[(row[2], str(row[5]).zfill(6))].setdefault(tuple(row[2:6]), row)
    crosswalk = collections.defaultdict(list)
    for village in villages:
        for state in {'36': ['28'], '37': ['01'], '38': ['25', '26']}.get(village['state'], [village['state'].zfill(2)]):
            code = str(village['census2011'] or '').zfill(6)
            if not code.isdigit() or int(code) in (0, 999999):
                continue
            crosswalk[(state, code)].append(village)
    groups = collections.defaultdict(list)
    identities = []
    held = collections.Counter()
    for key, current in crosswalk.items():
        if len({v['lgd'] for v in current}) != 1:
            held['split_lgd_census_crosswalk'] += 1
            continue
        published = list(census.get(key, {}).values())
        if len(published) != 1:
            held['missing_or_ambiguous_census_identity'] += 1
            continue
        village, row = current[0], published[0]
        groups[(village['state'], village['district'], '')].append(row)
        groups[(village['state'], village['district'], village['subdistrict'])].append(row)
        identities.append({'lgd': village['lgd'], 'state': village['state'], 'district': village['district'], 'subdistrict': village['subdistrict'], 'census': row[:6]})
    result = {'year': 2011, 'scope': 'Published rural village records grouped by current official LGD parent codes. Towns excluded. These are linked-record aggregates, not certified Census totals for current district boundaries.',
              'original_lgd_sha256': hashlib.sha256(directory.read_bytes()).hexdigest(),
              'inventory_sha256': hashlib.sha256(inventory.read_bytes()).hexdigest(),
              'groups': {':'.join(key): summaries(rows) for key, rows in groups.items()},
              'sources': json.loads(sources.read_text()), 'held': dict(held), 'linked_villages': len(identities)}
    output.write_text(json.dumps(result, separators=(',', ':')))
    output.with_suffix('.identities.jsonl').write_text(''.join(json.dumps(row) + '\n' for row in identities))
    print(json.dumps({'linked_villages': len(identities), 'districts': sum(not key[2] for key in groups), 'subdistricts': sum(bool(key[2]) for key in groups), 'held': dict(held)}), flush=True)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    for name in ['application', 'inventory', 'sources', 'output']:
        parser.add_argument(name, type=Path)
    args = parser.parse_args()
    build(args.application, args.inventory, args.sources, args.output)
