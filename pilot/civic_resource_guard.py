"""Shared admission control for the two isolated civic workers, not live services."""
from contextlib import contextmanager
import json
import os
from pathlib import Path
import time


def decision(available_mib, active, load, cpus, swap_mib_s, pressure):
    if active >= 2:
        return False, 'two civic workers already admitted'
    if available_mib < (4608 if active else 3072):
        return False, 'waiting for RAM headroom'
    if load > cpus:
        return False, 'waiting for CPU load to settle'
    if swap_mib_s > 8 or pressure > 5:
        return False, 'waiting for swap/memory pressure to settle'
    return True, 'admitted'


def sample():
    memory = dict((line.split(':')[0], int(line.split()[1])) for line in Path('/proc/meminfo').read_text().splitlines())
    def swap_pages():
        return sum(int(line.split()[1]) for line in Path('/proc/vmstat').read_text().splitlines()
                   if line.startswith(('pswpin ', 'pswpout ')))
    start = time.monotonic(); previous = swap_pages(); time.sleep(0.25)
    rate = max(0, swap_pages()-previous)*os.sysconf('SC_PAGE_SIZE')/(time.monotonic()-start)/1024**2
    path = Path('/proc/pressure/memory')
    pressure = float(path.read_text().splitlines()[0].split('avg10=')[1].split()[0]) if path.exists() else 0
    return memory['MemAvailable']/1024, os.getloadavg()[0], os.cpu_count() or 1, rate, pressure


def identity(pid):
    try:
        # Field 22 is process start ticks; parsing after ')' tolerates spaces in comm.
        return Path('/proc/'+str(pid)+'/stat').read_text().rsplit(')',1)[1].split()[19]
    except FileNotFoundError:
        return None


@contextmanager
def admission(root):
    import fcntl
    shared = root.parent if root.name == 'ocr-worker' else root
    ledger = shared/'active-civic-workers.json'
    guard = shared/'resource-admission.lock'
    me = str(os.getpid()); admitted = False
    def current():
        records = json.loads(ledger.read_text()) if ledger.exists() else {}
        return {pid:token for pid,token in records.items() if identity(pid)==token}
    try:
        with guard.open('a') as lock:
            fcntl.flock(lock,fcntl.LOCK_EX)
            records = current()
            ram, load, cpus, swap, pressure = sample()
            admitted, reason = decision(ram,len(records),load,cpus,swap,pressure)
            if admitted: records[me] = identity(me)
            ledger.write_text(json.dumps(records))
        yield admitted, reason
    finally:
        if admitted:
            with guard.open('a') as lock:
                fcntl.flock(lock,fcntl.LOCK_EX)
                records = current();records.pop(me,None);ledger.write_text(json.dumps(records))
