"""Publish completed, checksum-verified batches without restarting collectors."""
import argparse
import hashlib
import json
import os
import subprocess
import tarfile
import time
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
KEY='C:/Users/menha/.ssh/pollmedia_ed25519'
HOST='root@5.183.35.118'
OPTIONS=['-i',KEY,'-o','BatchMode=yes','-o','ConnectTimeout=15','-o','ServerAliveInterval=15','-o','ServerAliveCountMax=2']

def call(command):
    result=subprocess.run(command,stdin=subprocess.DEVNULL,capture_output=True,text=True,timeout=300)
    if result.returncode:raise RuntimeError(result.stderr[-2000:])
    return result.stdout

def run(args):
    work=Path(args.work).resolve();published=work/'published.json'
    lock=open(work/'publisher.lock','a+b');lock.write(b'0');lock.flush();lock.seek(0)
    if os.name=='nt':
        import msvcrt
        msvcrt.locking(lock.fileno(),msvcrt.LK_NBLCK,1)
    done=json.loads(published.read_bytes()) if published.exists() else {}
    while True:
        if (work/'publication-paused.json').exists():
            print('Satellite uploads paused pending server storage review.',flush=True)
            return
        registry=json.loads((work/'registry.json').read_bytes())
        for entry in registry['entries']:
            if (work/'publication-paused.json').exists():
                return
            key=entry['layer_id'];release=work/'releases'/Path(entry['base']).name
            if key in done or not release.exists():continue
            stage='/home/pollmedia/tmp/satellite-batch-'+key
            files={};bundle=work/(key+'-publish.tar')
            with tarfile.open(bundle,'w') as tar:
                for p in release.iterdir():
                    if p.suffix not in ['.json','.webp']:continue
                    name='dataset/'+p.name;tar.add(p,arcname=name);files[name]=hashlib.sha256(p.read_bytes()).hexdigest()
                source=work/key
                if not source.exists() and args.scratch_root:source=Path(args.scratch_root)/key
                recovery_path=work/'recovery-queue.json'
                recovery=json.loads(recovery_path.read_bytes()) if recovery_path.exists() else {}
                recovered=recovery.get(key,{})
                if recovered.get('base'):
                    candidate=Path(recovered['base'])
                    allowed=[work,ROOT/'tmp/india-map/satellite-national']
                    if args.scratch_root:allowed.append(Path(args.scratch_root))
                    assert not candidate.is_symlink() and candidate.name==key
                    assert candidate.resolve().parent in [p.resolve() for p in allowed]
                    source=candidate.resolve()
                for p in source.glob('*.json'):
                    name='sources/'+p.name;tar.add(p,arcname=name);files[name]=hashlib.sha256(p.read_bytes()).hexdigest()
                receipt=work/(key+'-receipt.json');receipt.write_text(json.dumps({'entry':entry,'files':files}),encoding='utf8');tar.add(receipt,arcname='receipt.json')
            try:
                free=int(call(['ssh',*OPTIONS,HOST,"python3 -c \"import shutil; print(shutil.disk_usage('/home/pollmedia').free)\""]).strip())
                if free<2_000_000_000+4*bundle.stat().st_size:
                    print('Server storage reserve reached; uploads stopped.',flush=True)
                    return
                call(['ssh',*OPTIONS,HOST,'mkdir -p '+stage])
                call(['scp',*OPTIONS,str(bundle),str(ROOT/'pilot/publish_national_satellite_server.py'),HOST+':'+stage+'/'])
                call(['ssh',*OPTIONS,HOST,'tar -xf '+stage+'/'+bundle.name+' -C '+stage+' && runuser -u pollmedia -- python3 '+stage+'/publish_national_satellite_server.py '+stage])
                done[key]=entry;published.write_text(json.dumps(done),encoding='utf8');print(key,'published',flush=True)
            except Exception as error:print(key,'publication deferred',str(error),flush=True)
        if not args.watch:break
        if not (work/'collector.lock').exists():
            print('Waiting for collector startup lock.',flush=True)
            time.sleep(10)
            continue
        with open(work/'collector.lock','r+b') as collector:
            try:
                if os.name=='nt':
                    msvcrt.locking(collector.fileno(),msvcrt.LK_NBLCK,1)
                    msvcrt.locking(collector.fileno(),msvcrt.LK_UNLCK,1)
                print('Collector stopped; publication pass finished. Review report.json for outstanding areas.',flush=True)
                break
            except OSError:
                pass
        time.sleep(60)

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--work',default=str(ROOT/'tmp/india-map/satellite-national'));parser.add_argument('--watch',action='store_true');parser.add_argument('--scratch-root');run(parser.parse_args())
