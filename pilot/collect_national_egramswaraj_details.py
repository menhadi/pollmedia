"""Network bridge for the authoritative server's resumable public-record queue.

Raw batches are retained on the server. Local batches are removed only after
acknowledged ingestion. No application files or private keys are copied here.
"""
import argparse
import json
import msvcrt
import os
from pathlib import Path
import shutil
import subprocess
import time
from datetime import datetime, timezone

HOST='root@5.183.35.118'
REPO='/home/pollmedia/app'
REMOTE='/home/pollmedia/tmp/national-egs-details-20261115'
ROOT=REPO+'/application/storage/app/egramswaraj-national-details'
SCRIPT='pilot/national_egramswaraj_details.py'

def main():
    p=argparse.ArgumentParser();p.add_argument('--root',type=Path,required=True);p.add_argument('--identity',required=True);p.add_argument('--collector',type=Path,required=True);p.add_argument('--limit',type=int,default=2000);a=p.parse_args();a.root.mkdir(parents=True,exist_ok=True)
    lock=open(a.root/'collector.lock','a+b');lock.seek(0);lock.write(b'1');lock.flush();lock.seek(0);msvcrt.locking(lock.fileno(),msvcrt.LK_NBLCK,1)
    ssh=['ssh','-o','BatchMode=yes','-o','ConnectTimeout=10','-o','ServerAliveInterval=20','-i',a.identity,HOST]
    scp=['scp','-o','BatchMode=yes','-o','ConnectTimeout=10','-i',a.identity]
    def run(cmd):
        for attempt in range(6):
            result=subprocess.run(cmd,capture_output=True,text=True)
            if result.returncode==0:return result.stdout.strip()
            print('Retry',attempt+1,(result.stderr or result.stdout)[-1200:],flush=True);time.sleep(min(30,5*(attempt+1)))
        raise RuntimeError('Command failed after retries: '+str(cmd[:3]))
    def command(action,extra=''):
        return run(ssh+['cd '+REPO+' && runuser -u pollmedia -- python3 '+SCRIPT+' '+action+' --work '+REMOTE+' '+extra])
    def heartbeat(status,**values):
        body={'pid':os.getpid(),'at':datetime.now(timezone.utc).isoformat(),'status':status,**values};(a.root/'heartbeat.json').write_text(json.dumps(body));print(json.dumps(body),flush=True)
    sent=set(json.loads((a.root/'sent.json').read_text())) if (a.root/'sent.json').exists() else set()
    def forward():
        files=sorted(a.root.glob('capture-*.zip'))
        for file in files:
            if file.name in sent:continue
            if shutil.disk_usage(a.root).free<512*1024*1024:raise RuntimeError('Local collector disk reserve reached')
            run(scp+[str(file),HOST+':'+REMOTE+'/raw/'+file.name])
            result=command('ingest','--archive '+REMOTE+'/raw/'+file.name)
            print(result,flush=True)
            sent.add(file.name);(a.root/'sent.json').write_text(json.dumps(sorted(sent)))
            print(command('publish'),flush=True)
            file.unlink()
    run(ssh+['mkdir -p '+REMOTE+'/raw && chown pollmedia:pollmedia '+REMOTE+'/raw'])
    heartbeat('starting')
    child=None
    try:
        while True:
            forward()
            checkpoint=a.root/'active.json'
            if checkpoint.exists():batch=json.loads(checkpoint.read_text())
            else:
                batch=json.loads(command('next','--limit '+str(a.limit)))
                if not batch['requests']:
                    if batch['preparation_complete'] and not batch['pending']:
                        print(command('publish'),flush=True);heartbeat('complete');break
                    heartbeat('waiting_for_queue',pending=batch['pending']);time.sleep(20);continue
                checkpoint.write_text(json.dumps(batch))
            if shutil.disk_usage(a.root).free<2*1024**3:raise RuntimeError('Collector disk reserve reached; batch remains resumable')
            manifest=a.root/(batch['batch']+'.json')
            run(scp+[HOST+':'+batch['manifest'],str(manifest)])
            prefix='capture-'+datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%f')
            log=open(a.root/(prefix+'.log'),'w')
            heartbeat('collecting',batch=batch['batch'],requests=batch['requests'])
            child=subprocess.Popen(['pwsh','-NoProfile','-File',str(a.collector),'-Root',str(a.root),'-Manifest',manifest.name,'-Prefix',prefix,'-Concurrency','16'],stdout=log,stderr=subprocess.STDOUT,creationflags=subprocess.CREATE_NO_WINDOW)
            while child.poll() is None:
                forward();time.sleep(5)
            log.close();forward()
            results=a.root/(prefix+'-results.json')
            if child.returncode!=0 or not results.exists():raise RuntimeError('Source collector stopped; active batch retained for resumption')
            run(scp+[str(results),HOST+':'+REMOTE+'/'+results.name]);print(command('finish','--result '+REMOTE+'/'+results.name),flush=True)
            print(command('publish'),flush=True)
            checkpoint.unlink();heartbeat('batch_complete',batch=batch['batch'])
            for completed in [manifest,results,a.root/(prefix+'.log')]:
                completed.unlink(missing_ok=True)
    except BaseException as error:
        if child is not None and child.poll() is None:child.terminate()
        heartbeat('stopped',error=str(error));raise

if __name__=='__main__':main()
