"""Keep authorized resumable workers running, without bypassing their locks."""
import importlib.util
import json
import msvcrt
import os
from pathlib import Path
import subprocess
import sys
import time

ROOT = Path('D:/pollmedia')
WORK = Path('C:/Users/menha/pollmedia-satellite-work/rollout')

def held(name):
    with (WORK/name).open('a+b') as f:
        f.seek(0)
        try:
            msvcrt.locking(f.fileno(), msvcrt.LK_NBLCK, 1)
            msvcrt.locking(f.fileno(), msvcrt.LK_UNLCK, 1)
            return False
        except OSError:
            return True

def run():
    lock = (WORK/'supervisor.lock').open('a+b')
    lock.write(b'0'); lock.flush(); lock.seek(0)
    msvcrt.locking(lock.fileno(), msvcrt.LK_NBLCK, 1)
    spec = importlib.util.spec_from_file_location('archive', ROOT/'tmp/storage-expansion/archive-local-satellite.py')
    module = importlib.util.module_from_spec(spec); spec.loader.exec_module(module)
    archive = None
    while not (WORK/'supervisor-stop').exists():
        try:
            if (WORK/'publication-paused.json').exists():
                time.sleep(60); continue
            plan = json.loads((WORK/'local-r2-archive-plan.json').read_text())
            remaining = any(Path(x['path']).exists() for x in plan)
            if remaining and not held('local-r2-archive.lock') and (archive is None or archive.poll() is not None):
                with (WORK/'archive-supervised.log').open('a') as out, (WORK/'archive-supervised-error.log').open('a') as err:
                    archive = subprocess.Popen([sys.executable, '-u', str(ROOT/'tmp/storage-expansion/archive-local-satellite.py')], cwd=ROOT, stdin=subprocess.DEVNULL, stdout=out, stderr=err, creationflags=subprocess.CREATE_NO_WINDOW)
                print('Archive started', archive.pid, flush=True)
            # Each bounded batch skips registered successes. Failed areas stay for review.
            registry = json.loads((WORK/'registry.json').read_text())
            report = json.loads((WORK/'report.json').read_text())['layers']
            catalogue = json.loads((ROOT/'application/storage/app/india-map/catalogue.json').read_text())['layers']
            ready = {e['layer_id'] for e in registry['entries']}
            pending = any(e['id'] not in ready and report.get(e['id'], {}).get('status') != 'failed' for e in catalogue)
            if pending and not held('collector.lock') and not held('publisher.lock'):
                if module.resume_if_ready():
                    print('Unfinished batch resumed', flush=True)
            if not pending:
                print('Initial pass exhausted; failed areas still require audit', flush=True)
        except Exception as error:
            print(type(error).__name__, str(error), flush=True)
        time.sleep(60)

if __name__ == '__main__':
    run()
