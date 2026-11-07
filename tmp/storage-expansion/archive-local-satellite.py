"""Move published districts' local raster caches to verified R2 storage via SSH.
Keeps source JSON, releases, registries, failed areas and live server assets intact.
"""
import argparse
import datetime
import hashlib
import json
import msvcrt
import os
from pathlib import Path
import shutil
import subprocess
import time

ROOT = Path('D:/pollmedia')
WORK = Path('C:/Users/menha/pollmedia-satellite-work/rollout')
SCRATCH = WORK.parent
OLD_WORK = ROOT/'tmp/india-map/satellite-national'
REMOTE = '/home/pollmedia/tmp/local-satellite-archive'
HOST = 'root@5.183.35.118'
OPTIONS = ['-i', 'C:/Users/menha/.ssh/pollmedia_ed25519', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=15', '-o', 'ServerAliveInterval=15', '-o', 'ServerAliveCountMax=2']

def call(args):
    for attempt in range(4):
        try:
            return subprocess.run(args, check=True, capture_output=True, text=True, stdin=subprocess.DEVNULL, timeout=1800).stdout
        except (subprocess.CalledProcessError, subprocess.TimeoutExpired) as error:
            # SCP replaces the same staging file; retrying never removes a source.
            if args[0] != 'scp' or attempt == 3:
                raise
            print('SCP retry', attempt + 1, str(getattr(error, 'stderr', 'timeout'))[-500:], flush=True)
            time.sleep(5 * (attempt + 1))

def sha(path):
    with path.open('rb') as f:
        return hashlib.file_digest(f, 'sha256').hexdigest()

def resume_if_ready():
    if shutil.disk_usage(SCRATCH).free < 5_000_000_000:
        return False
    handles = []
    try:
        for name in ['collector.lock', 'publisher.lock']:
            f = (WORK/name).open('r+b'); handles.append(f)
            msvcrt.locking(f.fileno(), msvcrt.LK_NBLCK, 1)
    except OSError:
        for f in handles: f.close()
        return False
    for f in handles: f.close()
    env = os.environ.copy(); env['PYTHONPATH'] = str(ROOT/'tmp/satellite-deps')
    import sys
    def launch(script, extra, label):
        with (WORK/(label+'.log')).open('a') as out, (WORK/(label+'-error.log')).open('a') as err:
            return subprocess.Popen([sys.executable, '-u', str(ROOT/'pilot'/script), '--work', str(WORK), '--scratch-root', str(SCRATCH), *extra], cwd=ROOT, env=env, stdin=subprocess.DEVNULL, stdout=out, stderr=err, creationflags=subprocess.CREATE_NO_WINDOW)
    collector = launch('collect_national_satellite.py', ['--limit', '1'], 'collector-r2-resume')
    for _ in range(60):
        if collector.poll() is not None:
            raise RuntimeError('Collector exited before acquiring lock')
        with (WORK/'collector.lock').open('r+b') as f:
            try:
                msvcrt.locking(f.fileno(), msvcrt.LK_NBLCK, 1)
                msvcrt.locking(f.fileno(), msvcrt.LK_UNLCK, 1)
            except OSError:
                break
        time.sleep(1)
    else:
        raise RuntimeError('Collector lock startup timed out; inspect before retry')
    publisher = launch('publish_national_satellite.py', ['--watch'], 'publisher-r2-resume')
    p = WORK/'monitor-status.json'
    try:
        status = json.loads(p.read_text())
    except (ValueError, FileNotFoundError):
        status = {}
    status.update(status='running_limited', collector_pid=collector.pid, publisher_pid=publisher.pid,
                  logs=['collector-r2-resume.log','collector-r2-resume-error.log','publisher-r2-resume.log','publisher-r2-resume-error.log'],
                  progress='Local space meets the user-authorized 5GB startup reserve; resumed one unfinished district and publisher. Check current space and logs before further batches.')
    temporary = WORK/'monitor-status.pending'
    temporary.write_text(json.dumps(status, indent=2))
    os.replace(temporary, p)
    print('RESUMED', collector.pid, publisher.pid, flush=True)
    return True

def run(limit, resume):
    lock = (WORK/'local-r2-archive.lock').open('a+b')
    lock.write(b'0'); lock.flush(); lock.seek(0)
    msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
    done = json.loads((WORK/'published.json').read_text())
    plan_path = WORK/'local-r2-archive-plan.json'
    if not plan_path.exists():
        plan = []
        for key in done:
            base = (SCRATCH/key).resolve()
            if base.parent != SCRATCH.resolve() or base.is_symlink():
                raise RuntimeError('Unsafe source directory')
            for p in sorted(base.glob('*.npz')):
                if p.is_symlink():
                    raise RuntimeError('Symlink source')
                plan.append({'path': str(p.resolve()), 'layer_id': key, 'bytes': p.stat().st_size})
        plan_path.write_text(json.dumps(plan, indent=2))
    plan = json.loads(plan_path.read_text())
    receipts = WORK/'local-r2-archive-receipts.jsonl'
    existing = {x['path']: x for x in map(json.loads, receipts.read_text().splitlines())} if receipts.exists() else {}
    call(['ssh', *OPTIONS, HOST, 'mkdir -p '+REMOTE])
    call(['scp', *OPTIONS, str(ROOT/'tmp/storage-expansion/archive-local-satellite-file.php'), HOST+':'+REMOTE+'/archive.php'])
    moved = 0
    resumed = False
    for item in plan:
        p = Path(item['path'])
        if (item['layer_id'] not in done
                or p.parent.parent.resolve() not in {SCRATCH.resolve(), OLD_WORK.resolve()}
                or p.parent.name != item['layer_id'] or p.parent.is_symlink()
                or p.suffix != '.npz' or p.is_symlink()):
            raise RuntimeError('Unapproved source')
        if not p.exists():
            if str(p) not in existing:
                raise RuntimeError('Missing unrecorded source')
            continue
        if p.stat().st_size != item['bytes']:
            raise RuntimeError('Source size changed')
        digest = sha(p)
        if str(p) in existing:
            receipt = existing[str(p)]
            if receipt['sha256'] != digest:
                raise RuntimeError('Retained source changed')
        else:
            free = int(call(['ssh', *OPTIONS, HOST, "python3 -c \"import shutil; print(shutil.disk_usage('/home/pollmedia').free)\""]).strip())
            if free < 2_000_000_000 + item['bytes']*2:
                raise RuntimeError('Server staging reserve reached')
            manifest = dict(item, sha256=digest)
            m = WORK/'local-r2-current.json'; m.write_text(json.dumps(manifest))
            call(['scp', *OPTIONS, str(p), HOST+':'+REMOTE+'/payload'])
            call(['scp', *OPTIONS, str(m), HOST+':'+REMOTE+'/manifest.json'])
            receipt = json.loads(call(['ssh', *OPTIONS, HOST, 'runuser -u pollmedia -- php '+REMOTE+'/archive.php']))
            if any(receipt.get(k) != v for k, v in manifest.items()):
                raise RuntimeError('Receipt mismatch')
            with receipts.open('a', encoding='utf8') as f:
                f.write(json.dumps(receipt)+'\n'); f.flush(); os.fsync(f.fileno())
        if sha(p) != digest:
            raise RuntimeError('Source changed after verification')
        p.unlink()
        moved += 1
        print(json.dumps({'moved': str(p), 'bytes': item['bytes'], 'free': shutil.disk_usage(SCRATCH).free}), flush=True)
        if resume and not resumed:
            resumed = resume_if_ready()
        if limit and moved >= limit:
            break
    print('PASS FINISHED', flush=True)

if __name__ == '__main__':
    parser = argparse.ArgumentParser(); parser.add_argument('--limit', type=int, default=0); parser.add_argument('--resume', action='store_true')
    args = parser.parse_args(); run(args.limit, args.resume)
