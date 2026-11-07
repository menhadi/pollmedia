"""Publish a verified imagery batch under the existing deployment locks."""
import datetime
import fcntl
import hashlib
import json
import os
import shutil
import sys
import subprocess
from pathlib import Path

def digest(path):
    with path.open('rb') as stream:
        value=hashlib.sha256()
        for chunk in iter(lambda:stream.read(1048576),b''):value.update(chunk)
        return value.hexdigest()

def publish(stage):
    root=Path('/home/pollmedia/app/application');stage=Path(stage)
    locks=[]
    for name in ['/home/pollmedia/.pollmedia-live-deployment.lock','/home/pollmedia/tmp/.pollmedia-election-release.lock']:
        handle=open(name);locks.append(handle);fcntl.flock(handle,fcntl.LOCK_EX|fcntl.LOCK_NB)
    receipt=json.loads((stage/'receipt.json').read_text());entry=receipt['entry']
    for name,sha in receipt['files'].items():assert digest(stage/name)==sha,'Staged checksum: '+name
    paths=[root/'storage/app/india-map/snapshot'/entry['file'],root/'storage/app'/entry['file'],root/'public'/entry['file']]
    assert any(p.exists() and digest(p)==entry['geometry_sha256'] for p in paths),'Original map edition changed'
    release=stage/'dataset';data=json.loads((release/'comparison.json').read_text());index=json.loads((release/'index.json').read_text())
    assert data['geometry_sha256']==entry['geometry_sha256'] and data['layer_id']==entry['layer_id']
    assert data['dates']==entry['dates'] and len(data['scenes'])>=2
    assert data['minimum_paired_coverage_pct']==80 and data['minimum_pixels']==25
    assert any(r['ndvi_change'] is not None for r in data['villages'])
    for row in data['villages']:
        if row['ndvi_change'] is not None:
            assert row['paired_coverage_pct']>=80 and row['paired_pixels']>=25
            assert -1<=row['before_ndvi']<=1 and -1<=row['after_ndvi']<=1
            assert abs(row['after_ndvi']-row['before_ndvi']-row['ndvi_change'])<=.00011
    for name,sha in data['files'].items():assert digest(release/name)==sha
    registry_path=root/'public/maps/satellite-registry.json';registry=json.loads(registry_path.read_text())
    existing=next((r for r in registry['entries'] if r['layer_id']==entry['layer_id']),None)
    if existing:
        assert existing==entry,'Conflicting satellite edition'
        subprocess.run(['php','artisan','maps:storage','--release='+Path(entry['base']).name,'--apply-default','--no-interaction'],cwd=root,check=True)
        return
    target=root/'public'/entry['base'].lstrip('/');assert target.parent==root/'public/maps'
    assert shutil.disk_usage(root).free>2_000_000_000+sum(p.stat().st_size for p in release.iterdir()),'Storage reserve reached; batch withheld'
    if target.exists():
        for name in ['comparison.json','index.json',*data['files']]:assert digest(target/name)==digest(release/name)
    else:shutil.copytree(release,target)
    evidence=root/'storage/app/satellite'/target.name;evidence.mkdir(parents=True,exist_ok=True)
    for p in (stage/'sources').glob('*.json'):shutil.copy2(p,evidence/p.name)
    shutil.copy2(stage/'receipt.json',evidence/'publication-receipt.json')
    registry['entries'].append(entry)
    for kind in ['lgd','census']:
        for code in index[kind]:registry[kind].setdefault(code,[]).append(entry['layer_id'])
    registry['complete']=False;registry['updated_at']=datetime.datetime.now(datetime.timezone.utc).isoformat()
    backup=root.parent/'releases'/('satellite-registry-'+datetime.datetime.now().strftime('%Y%m%dT%H%M%S')+'.json');shutil.copy2(registry_path,backup)
    pending=registry_path.with_suffix('.pending');pending.write_text(json.dumps(registry,separators=(',',':')));os.replace(pending,registry_path)
    subprocess.run(['php','artisan','maps:storage','--release='+target.name,'--apply-default','--no-interaction'],cwd=root,check=True)
    print(json.dumps({'published':entry['layer_id'],'district':entry['district'],'ready_layers':len(registry['entries'])}),flush=True)

if __name__=='__main__':publish(sys.argv[1])
