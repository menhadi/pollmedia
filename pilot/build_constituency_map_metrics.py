"""Summarize existing village values through fully covered official LGD membership."""
import argparse
import collections
import hashlib
import json
import sqlite3
from pathlib import Path
from build_india_overview_metrics import summaries


def memberships(database):
    children=collections.defaultdict(set)
    for lgd,state,district,subdistrict in database.execute('SELECT lgd,state,district,subdistrict FROM ancestry'):
        children[(state,'Village',lgd)].add(lgd)
        children[(state,'District',district)].add(lgd)
        children[(state,'SubDistrict',subdistrict)].add(lgd)
    assigned=collections.defaultdict(dict)
    held=collections.Counter()
    for state,pc,ac,kind,code,coverage in database.execute('SELECT state,pc,ac,entity_type,entity_code,coverage FROM membership'):
        if kind not in {'Village','District','SubDistrict'}:
            held['urban_or_ward_membership_without_village_crosswalk']+=1
            continue
        if kind!='Village' and coverage!='Fully Covered':
            held['partial_parent_not_expanded']+=1
            continue
        for seat_kind,seat in [('pc',pc),('ac',ac)]:
            if not seat:
                continue
            key=(state,seat_kind,seat)
            for lgd in children.get((state,kind,code),set()):
                assigned[key][lgd]=assigned[key].get(lgd,False) or coverage!='Fully Covered'
    owners=collections.defaultdict(set)
    for (state,kind,seat),villages in assigned.items():
        for lgd,partial in villages.items():
            if not partial:owners[(state,kind,lgd)].add(seat)
    for (state,kind,seat),villages in assigned.items():
        for lgd,partial in villages.items():
            if partial or len(owners[(state,kind,lgd)])!=1:
                villages[lgd]=True
                held['partial_or_conflicting_village_membership']+=1
    return assigned,held


def build(application,output):
    directory=application/'storage/app/electoral-villages'
    source=directory/'overview-metrics'
    metadata=json.loads((source/'india-overview-metrics.json').read_bytes())
    base=directory/'links.sqlite'
    assert hashlib.sha256(base.read_bytes()).hexdigest()==metadata['original_lgd_sha256']
    inventory=source/'overview-village-values-20261013.jsonl'
    assert hashlib.sha256(inventory.read_bytes()).hexdigest()==metadata['inventory_sha256']
    db=sqlite3.connect(f'file:{base.as_posix()}?mode=ro',uri=True)
    assigned,held=memberships(db)
    names={':'.join(row[:3]):row[3] for row in db.execute('SELECT state,kind,code,name FROM seats')}
    db.close()
    targets=collections.defaultdict(list)
    for seat,villages in assigned.items():
        for lgd,partial in villages.items():
            if not partial:targets[(seat[0],lgd)].append(seat)
    values={}
    for line in inventory.open(encoding='utf-8'):
        row=json.loads(line);values[(row[0],row[1])]=row
    groups=collections.defaultdict(dict)
    evidence=output.with_suffix('.identities.jsonl').open('wb')
    for line in (source/'india-overview-metrics.identities.jsonl').open(encoding='utf-8'):
        identity=json.loads(line);row=values.get(tuple(identity['census'][:2]))
        if not row or row[:6]!=identity['census']:
            raise ValueError('Existing Census record identity changed')
        seats=targets.get((identity['state'],identity['lgd']),[])
        for seat in seats:groups[seat][tuple(row[2:6])]=row
        if seats:evidence.write((json.dumps({'lgd':identity['lgd'],'census':identity['census'],'seats':[':'.join(s) for s in seats]})+'\n').encode('utf-8'))
    evidence.close()
    result={'year':2011,'scope':'Existing rural Census records grouped by fully covered, non-conflicting official LGD constituency membership. Towns and wards excluded. Linked-record summaries are not official constituency totals or boundary equivalence.',
            'original_lgd_sha256':metadata['original_lgd_sha256'],'inventory_sha256':metadata['inventory_sha256'],'groups':{},'held':dict(held),'sources':metadata['sources']}
    for seat,villages in assigned.items():
        rows=groups.get(seat,{})
        result['groups'][':'.join(seat)]={**summaries(rows.values()),'name':names.get(':'.join(seat)),
            'fully_covered_villages':sum(not p for p in villages.values()),'withheld_villages':sum(p for p in villages.values())}
    output.write_bytes(json.dumps(result,separators=(',',':')).encode('utf-8'))
    print(json.dumps({'seats':len(result['groups']),'with_values':sum(bool(v['counts'].get('records')) for v in result['groups'].values()),'held':result['held']}),flush=True)


if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('application',type=Path);p.add_argument('output',type=Path);args=p.parse_args();build(args.application,args.output)
