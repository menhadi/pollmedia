"""Resumable network bridge with two bounded download lanes and one publisher.

All source captures and authoritative queue state remain on the live server.
Financial and activity downloads share a maximum of sixteen connections. Only
one thread imports/publishes captures, at low server CPU and I/O priority.
"""
import argparse
import json
import os
from pathlib import Path
import shutil
import subprocess
import threading
import time
from datetime import datetime, timezone

HOST='root@5.183.35.118'
REPO='/home/pollmedia/app'
REMOTE='/home/pollmedia/tmp/national-egs-details-20261115'
SCRIPT='pilot/national_egramswaraj_details.py'

def main():
    p=argparse.ArgumentParser();p.add_argument('--root',type=Path,required=True);p.add_argument('--identity',required=True);p.add_argument('--collector',type=Path,required=True);p.add_argument('--limit',type=int,default=2000);a=p.parse_args();a.root.mkdir(parents=True,exist_ok=True)
    lock=open(a.root/'collector.lock','a+b');lock.seek(0);lock.write(b'1');lock.flush();lock.seek(0)
    if os.name=='nt':
        import msvcrt
        msvcrt.locking(lock.fileno(),msvcrt.LK_NBLCK,1)
    else:
        import fcntl
        fcntl.flock(lock,fcntl.LOCK_EX|fcntl.LOCK_NB)
    ssh=['ssh','-o','BatchMode=yes','-o','ConnectTimeout=10','-o','ServerAliveInterval=20','-o','ServerAliveCountMax=3','-i',a.identity,HOST]
    scp=['scp','-o','BatchMode=yes','-o','ConnectTimeout=10','-o','ServerAliveInterval=20','-o','ServerAliveCountMax=3','-i',a.identity]
    io_lock=threading.Lock();state_lock=threading.Lock();stop=threading.Event();publish_needed=threading.Event()
    lanes={};children={};errors=[];finished={name:threading.Event() for name in ['financial','activities']}
    def emit(value):
        with io_lock:print(json.dumps(value) if isinstance(value,dict) else value,flush=True)
    def heartbeat(pool,status,**values):
        with state_lock:
            lanes[pool]={'status':status,**values}
            body={'pid':os.getpid(),'at':datetime.now(timezone.utc).isoformat(),'status':'stopped' if stop.is_set() else 'running','lanes':dict(lanes)}
            tmp=a.root/'heartbeat.tmp';tmp.write_text(json.dumps(body));tmp.replace(a.root/'heartbeat.json')
        emit(body)
    def run(cmd):
        for attempt in range(6):
            result=subprocess.run(cmd,capture_output=True,text=True)
            if result.returncode==0:return result.stdout.strip()
            emit({'retry':attempt+1,'error':(result.stderr or result.stdout)[-1200:]});time.sleep(min(30,5*(attempt+1)))
        raise RuntimeError('Command failed after retries: '+str(cmd[:3]))
    def command(action,extra=''):
        started=time.monotonic()
        result=run(ssh+['cd '+REPO+' && ionice -c2 -n7 nice -n15 runuser -u pollmedia -- python3 '+SCRIPT+' '+action+' --work '+REMOTE+' '+extra])
        emit({'operation':action,'seconds':round(time.monotonic()-started,3)})
        return result
    sent=set(json.loads((a.root/'sent.json').read_text())) if (a.root/'sent.json').exists() else set()
    def forward():
        # Called only by the main thread: no concurrent application imports.
        for file in sorted(a.root.glob('capture-*.zip')):
            if file.name in sent:continue
            if shutil.disk_usage(a.root).free<512*1024**2:raise RuntimeError('Local collector disk reserve reached')
            run(scp+[str(file),HOST+':'+REMOTE+'/raw/'+file.name])
            emit(command('ingest','--archive '+REMOTE+'/raw/'+file.name))
            sent.add(file.name);(a.root/'sent.json').write_text(json.dumps(sorted(sent)))
            emit(command('publish'));file.unlink()
    run(ssh+['mkdir -p '+REMOTE+'/raw && chown pollmedia:pollmedia '+REMOTE+'/raw'])
    emit(command('optimize'));forward()
    legacy=a.root/'active.json'
    if legacy.exists():
        old=json.loads(legacy.read_text());emit(command('requeue','--batch '+old['batch']))
        legacy.replace(a.root/('retired-'+old['batch']+'.json'))
    def lane(pool):
        other='activities' if pool=='financial' else 'financial';checkpoint=a.root/('active-'+pool+'.json')
        try:
            while not stop.is_set():
                started=time.monotonic()
                if checkpoint.exists():
                    saved=json.loads(checkpoint.read_text());batch=json.loads(command('resume','--batch '+saved['batch']+' --pool '+pool))
                    if not batch['requests']:
                        checkpoint.unlink();continue
                else:batch=json.loads(command('next','--limit '+str(a.limit)+' --pool '+pool))
                if not batch['requests']:
                    if batch['preparation_complete'] and not batch['pending']:
                        heartbeat(pool,'complete');finished[pool].set();return
                    heartbeat(pool,'waiting',pending=batch['pending']);stop.wait(20);continue
                checkpoint.write_text(json.dumps(batch))
                if shutil.disk_usage(a.root).free<2*1024**3:raise RuntimeError('Collector disk reserve reached; batch remains resumable')
                manifest=a.root/(batch['batch']+'.json');run(scp+[HOST+':'+batch['manifest'],str(manifest)])
                prefix='capture-'+pool+'-'+datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%f')
                slots=16 if finished[other].is_set() else 8
                log=open(a.root/(prefix+'.log'),'w');heartbeat(pool,'collecting',batch=batch['batch'],requests=batch['requests'],connections=slots)
                child=subprocess.Popen(['pwsh','-NoProfile','-File',str(a.collector),'-Root',str(a.root),'-Manifest',manifest.name,'-Prefix',prefix,'-Concurrency',str(slots)],stdout=log,stderr=subprocess.STDOUT,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
                with state_lock:children[pool]=child
                while child.poll() is None and not stop.wait(1):pass
                if stop.is_set() and child.poll() is None:child.terminate()
                child.wait();log.close()
                with state_lock:children.pop(pool,None)
                if stop.is_set():return
                # Wait for the single publisher to acknowledge every closed archive.
                heartbeat(pool,'publishing',batch=batch['batch'])
                while any(a.root.glob(prefix+'-*.zip')) and not stop.wait(1):pass
                if stop.is_set():return
                result=a.root/(prefix+'-results.json')
                if child.returncode or not result.exists():raise RuntimeError('Source collector stopped; batch retained for resumption')
                run(scp+[str(result),HOST+':'+REMOTE+'/'+result.name]);emit(command('finish','--result '+REMOTE+'/'+result.name));publish_needed.set()
                checkpoint.unlink();heartbeat(pool,'batch_complete',batch=batch['batch'],requests=batch['requests'],seconds=round(time.monotonic()-started,3))
                for completed in [manifest,result,a.root/(prefix+'.log')]:completed.unlink(missing_ok=True)
        except BaseException as error:
            errors.append(str(error));stop.set();heartbeat(pool,'error',error=str(error))
    threads=[threading.Thread(target=lane,args=(name,),daemon=True) for name in ['financial','activities']]
    for t in threads:t.start()
    try:
        while any(t.is_alive() for t in threads) and not stop.is_set():
            forward()
            if publish_needed.is_set():publish_needed.clear();emit(command('publish'))
            stop.wait(1)
        if not stop.is_set():forward();emit(command('publish'))
    except BaseException as error:
        errors.append(str(error));stop.set();heartbeat('publisher','error',error=str(error))
    finally:
        with state_lock:
            for child in children.values():
                if child.poll() is None:child.terminate()
        for t in threads:t.join(timeout=10)
    if errors:raise RuntimeError('; '.join(errors))
    heartbeat('publisher','complete')

if __name__=='__main__':main()
