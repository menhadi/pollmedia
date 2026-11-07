"""Resumable seasonal satellite comparisons keyed by immutable map editions."""
import argparse
import hashlib
import json
import os
import shutil
import time
from collections import defaultdict
from datetime import datetime, timezone
from pathlib import Path

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry
from shapely.geometry import box, shape
from shapely.ops import unary_union
from build_pilibhit_satellite import build, sha

ROOT=Path(__file__).resolve().parents[1]
SESSION=requests.Session()
SESSION.mount('https://',HTTPAdapter(max_retries=Retry(total=4,backoff_factor=2,status_forcelist=[429,500,502,503,504],allowed_methods=['GET','POST'])))

def save(path,data):
    path.parent.mkdir(parents=True,exist_ok=True)
    pending=path.with_suffix(path.suffix+'.pending')
    pending.write_text(json.dumps(data,separators=(',',':')),encoding='utf8');pending.replace(path)

def catalogue(path,bbox,period,official=False):
    if path.exists():return json.loads(path.read_bytes())
    url='https://stac.dataspace.copernicus.eu/v1/search' if official else 'https://earth-search.aws.element84.com/v1/search'
    query={'collections':'sentinel-2-l2a' if official else 'sentinel-2-c1-l2a','bbox':','.join(map(str,bbox)),'datetime':period,'limit':100}
    items=[];pages=[]
    while url:
        response=SESSION.get(url,params=query,timeout=120);response.raise_for_status();data=response.json();pages.append(data);items.extend(data['features'])
        link=next((link for link in data.get('links',[]) if link['rel']=='next'),None)
        url=link['href'] if link else None;query=None
        if len(pages)>100:raise ValueError('Catalogue pagination exceeds expected range')
    result={'type':'FeatureCollection','features':items,'request':{'bbox':bbox,'datetime':period},'retrieved_at':datetime.now(timezone.utc).isoformat(),'pages':pages}
    save(path,result);return result

def group_scenes(items):
    groups=defaultdict(dict)
    for item in items:
        p=item['properties'];product=p['s2:product_uri'];orbit=product.split('_')[4]
        key=(p['datetime'][:10],p['platform'],orbit)
        tile=p['grid:code'];old=groups[key].get(tile)
        if old is None or p.get('created','')>old['properties'].get('created',''):groups[key][tile]=item
    return {key:list(tiles.values()) for key,tiles in groups.items()}

def choose_pair(first,last,bbox):
    target=box(*bbox);pairs=[]
    for a,aa in group_scenes(first).items():
        for b,bb in group_scenes(last).items():
            if a[1:]!=b[1:] or abs(int(a[0][-2:])-int(b[0][-2:]))>7:continue
            tiles=set(i['properties']['grid:code'] for i in aa)&set(i['properties']['grid:code'] for i in bb)
            left=[i for i in aa if i['properties']['grid:code'] in tiles];right=[i for i in bb if i['properties']['grid:code'] in tiles]
            if not left:continue
            coverage=min(unary_union([shape(i['geometry']) for i in rows]).intersection(target).area/target.area for rows in [left,right])
            if coverage<.98:continue
            cloud=sum(i['properties'].get('eo:cloud_cover',100) for i in left+right)/len(left+right)
            pairs.append(((cloud,abs(int(a[0][-2:])-int(b[0][-2:])),a[0],b[0]),a,b,left,right))
    if not pairs:raise ValueError('No same-sensor/orbit November pair within seven calendar days covering 98% of the map extent; needs alternate window or tiled processing')
    _,a,b,aa,bb=min(pairs,key=lambda row:row[0])
    return {'dates':[a[0],b[0]],'platform':a[1],'orbit':a[2]},[aa,bb]

def should_attempt(key, ready, previous, retry, recovery):
    return key not in ready and (previous.get('status')!='failed' or retry or recovery.get('status')=='queued')

def run(args):
    work=Path(args.work).resolve();work.mkdir(parents=True,exist_ok=True)
    archive_gate=work/'archive-before-collection.json'
    if archive_gate.exists():
        plan=json.loads((work/'local-r2-archive-plan.json').read_bytes())
        if any(Path(item['path']).exists() for item in plan):
            print('Waiting for requested local R2 archive to finish before new collection.',flush=True)
            return
        receipts={r['path']:r for r in map(json.loads,(work/'local-r2-archive-receipts.jsonl').read_text().splitlines())}
        assert all(item['path'] in receipts and all(receipts[item['path']][k]==item[k] for k in ['layer_id','bytes']) and len(receipts[item['path']]['sha256'])==64 for item in plan), 'Archive recovery receipts incomplete'
        archive_gate.unlink()
    scratch=Path(args.scratch_root).resolve() if args.scratch_root else work
    scratch.mkdir(parents=True,exist_ok=True)
    lock=open(work/'collector.lock','a+b');lock.seek(0);lock.write(b'0');lock.flush();lock.seek(0)
    if os.name=='nt':
        import msvcrt
        msvcrt.locking(lock.fileno(),msvcrt.LK_NBLCK,1)
    else:
        import fcntl
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    layers=json.loads((ROOT/'application/storage/app/india-map/catalogue.json').read_bytes())['layers']
    registry_path=work/'registry.json'
    registry=json.loads((registry_path if registry_path.exists() else ROOT/'application/public/maps/satellite-registry.json').read_bytes())
    registry['planned_layers']=len(layers);registry['complete']=False
    report_path=work/'report.json';report=json.loads(report_path.read_bytes()) if report_path.exists() else {'layers':{}}
    ready={entry['layer_id']:entry for entry in registry['entries']}
    recovery_path=work/'recovery-queue.json'
    recovery=json.loads(recovery_path.read_bytes()) if recovery_path.exists() else {}
    completed=0
    for layer in sorted(layers,key=lambda row:(recovery.get(row['id'],{}).get('status')!='queued',row['bytes'])):
        key=layer['id'];previous=report['layers'].get(key,{})
        if key in ready:
            report['layers'].setdefault(key,{'status':'ready','reused':True,'district':layer['district'],'state':layer['state']});continue
        retry_job=recovery.get(key,{})
        queued=retry_job.get('status')=='queued'
        if not should_attempt(key,ready,previous,args.retry,retry_job):continue
        if args.limit and completed>=args.limit:break
        base=work/key if (work/key).exists() else scratch/key
        if queued and retry_job.get('base'):
            candidate=Path(retry_job['base'])
            allowed=[work,scratch,ROOT/'tmp/india-map/satellite-national']
            assert not candidate.is_symlink() and candidate.name==key
            assert candidate.resolve().parent in [p.resolve() for p in allowed]
            base=candidate.resolve()
        if shutil.disk_usage(base if base.exists() else scratch).free<5_000_000_000:raise RuntimeError('Less than 5 GB free on processing drive; stopped before exhausting local storage')
        if shutil.disk_usage(work).free<2_000_000_000:raise RuntimeError('Less than 2 GB free on release drive; publication workspace reserve reached')
        base.mkdir(exist_ok=True)
        if queued:
            retry_job.update(status='attempting',started_at=datetime.now(timezone.utc).isoformat())
            save(recovery_path,recovery)
        try:
            print(key,layer['state'],layer['district'],'starting',flush=True)
            report['layers'][key]={'status':'processing','district':layer['district'],'state':layer['state']};save(report_path,report)
            paths=[ROOT/'application/storage/app/india-map/snapshot'/layer['file'],ROOT/'application/storage/app'/layer['file'],ROOT/'application/public'/layer['file']]
            geometry=next((p for p in paths if p.exists() and sha(p)==layer['sha256']),base/'geometry.geojson')
            if not geometry.exists():
                response=SESSION.get('https://pollmedia.org/api/india-map/layers/'+key,timeout=120);response.raise_for_status();geometry.write_bytes(response.content)
            assert sha(geometry)==layer['sha256'],'Map edition checksum changed'
            geo=json.loads(geometry.read_bytes());assert geo.get('type')=='FeatureCollection','Source requires existing row-to-feature mapping'
            snapshots=[catalogue(base/f'search-{year}.json',layer['bbox'],f'{year}-11-01T00:00:00Z/{year}-11-30T23:59:59Z') for year in [2023,2024]]
            config,scenes=choose_pair(snapshots[0]['features'],snapshots[1]['features'],layer['bbox'])
            config.update({'layer_id':key,'geometry_sha256':layer['sha256'],'crs':'EPSG:'+str(scenes[0][0]['properties']['proj:epsg'])})
            tile_settings=work/'tiled-processing.json'
            if tile_settings.exists():
                settings=json.loads(tile_settings.read_bytes())
                if settings.get('enabled'):
                    config.update(tiled=True,tile_size=min(1024,max(32,int(settings.get('tile_size',1024)))),
                                  tile_workers=min(2,max(1,int(settings.get('max_workers',2)))),
                                  archive_receipts=str(work/'local-r2-archive-receipts.jsonl'))
            for date,items in zip(config['dates'],scenes):
                save(base/f'c1-{date}.json',{'type':'FeatureCollection','features':items})
                catalogue(base/f'official-{date}.json',layer['bbox'],date+'T00:00:00Z/'+date+'T23:59:59Z',True)
            save(base/'job.json',config)
            metrics=base/'existing-metrics.json'
            if not metrics.exists():
                response=SESSION.get('https://pollmedia.org/api/india-map/metrics/'+key,params={'sha':layer['sha256'],'year':2011},timeout=180);response.raise_for_status();save(metrics,response.json())
            release=work/'releases'/('satellite-'+key+'-20261014');build(base,geometry,release,config)
            data=json.loads((release/'comparison.json').read_bytes());index=json.loads((release/'index.json').read_bytes())
            assert len(data['villages'])==len(geo['features']),'Not every original polygon accounted for'
            eligible=sum(row['ndvi_change'] is not None for row in data['villages'])
            assert eligible,'No polygons meet paired clear-pixel requirements'
            for name,digest in data['files'].items():assert sha(release/name)==digest
            entry={'layer_id':key,'file':layer['file'],'geometry_sha256':layer['sha256'],'district':layer['district'],'state':layer['state'],'base':'/maps/'+release.name+'/','dates':data['dates']}
            registry['entries'].append(entry)
            for kind in ['lgd','census']:
                for code in index[kind]:registry[kind].setdefault(code,[]).append(key)
            save(registry_path,registry)
            report['layers'][key]={'status':'ready','eligible_polygons':eligible,'polygons':len(data['villages']),'dates':data['dates'],'district':layer['district'],'state':layer['state']}
            # Keep source observations and checksums for reproducibility. Stop on low disk rather than silently discard them.
            completed+=1;print(key,'ready',eligible,flush=True)
        except Exception as error:
            report['layers'][key]={'status':'failed','reason':str(error),'district':layer['district'],'state':layer['state']}
            print(key,'failed',str(error),flush=True)
        save(report_path,report)
        if queued:
            retry_job.update(status=report['layers'][key]['status'],result=report['layers'][key],finished_at=datetime.now(timezone.utc).isoformat())
            save(recovery_path,recovery)
    save(report_path,report);save(registry_path,registry)

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--work',default=str(ROOT/'tmp/india-map/satellite-national'));parser.add_argument('--scratch-root');parser.add_argument('--limit',type=int,default=0);parser.add_argument('--retry',action='store_true');run(parser.parse_args())
