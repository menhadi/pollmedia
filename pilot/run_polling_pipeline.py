"""Run a resumable collection/extraction batch and record remaining national coverage gaps."""
import argparse
import ctypes
from datetime import datetime, timezone
import json
import os
from pathlib import Path
import subprocess
import sys


def wait_for_processes(pids):
    if not pids:
        return
    if os.name != 'nt':
        raise ValueError('--wait-pid is supported only on the Windows collection workstation')
    kernel = ctypes.WinDLL('kernel32',use_last_error=True)
    kernel.OpenProcess.argtypes = [ctypes.c_uint32,ctypes.c_int,ctypes.c_uint32]
    kernel.OpenProcess.restype = ctypes.c_void_p
    kernel.WaitForSingleObject.argtypes = [ctypes.c_void_p,ctypes.c_uint32]
    kernel.CloseHandle.argtypes = [ctypes.c_void_p]
    handles = [kernel.OpenProcess(0x00100000,False,pid) for pid in pids]
    try:
        for handle in handles:
            if handle:
                while True:
                    result=kernel.WaitForSingleObject(handle,1000)
                    if result == 0:
                        break
                    if result != 258:
                        raise OSError('Unable to wait for an existing collection process')
    finally:
        for handle in handles:
            if handle:
                kernel.CloseHandle(handle)


def main(args):
    root=Path(__file__).resolve().parents[1]
    store=root/'application/storage/app/private/polling-station-sources'
    lock=store/'pipeline.lock'
    try:
        descriptor=os.open(lock,os.O_CREAT|os.O_EXCL|os.O_WRONLY)
    except FileExistsError:
        raise RuntimeError('A pipeline lock already exists; inspect its PID before starting another batch')
    os.write(descriptor,str(os.getpid()).encode());os.close(descriptor)
    status={'status':'running','started_at':datetime.now(timezone.utc).isoformat(),'pid':os.getpid(),'phases':[]}

    def save(phase):
        status['phase']=phase;status['updated_at']=datetime.now(timezone.utc).isoformat()
        temporary=store/'pipeline-status.tmp'
        temporary.write_text(json.dumps(status,indent=2),encoding='utf-8');temporary.replace(store/'pipeline-status.json')

    def run(script,*arguments):
        save(script)
        print('Starting '+script,flush=True)
        subprocess.run([sys.executable,str(root/'pilot'/script),*map(str,arguments)],cwd=root,check=True)
        status['phases'].append({'script':script,'finished_at':datetime.now(timezone.utc).isoformat()})

    try:
        save('waiting_for_existing_collection_jobs')
        wait_for_processes(args.wait_pid)
        run('collect_polling_sources.py','--pages',args.pages,'--workers','2','--rediscover')
        save('waiting_for_existing_extraction_jobs')
        wait_for_processes(args.wait_extraction_pid)
        run('extract_polling_sources.py','--workers','2')
        run('remap_polling_tables.py')
        run('build_polling_index.py')
        run('audit_election_coverage.py')
        if args.output:
            run('preserve_polling_sources.py',args.output.resolve())
        index=json.loads((store/'index.json').read_text(encoding='utf-8'))
        status.update(status='batch_finished_with_coverage_gaps',documents=len(index['sources']),
                      polling_rows=sum(s['polling_rows'] for s in index['sources']),
                      pending_discovery_pages=sum(s['pending_pages'] for s in index['states']),
                      note='Batch completion is not national completeness. Unavailable links, scanned pages, unmapped layouts and missing state/year sources still require work.')
        save('batch_finished')
    except Exception as error:
        status.update(status='failed',error=str(error));save(status.get('phase','starting'));raise
    finally:
        lock.unlink(missing_ok=True)


if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--wait-pid',type=int,action='append',default=[])
    parser.add_argument('--wait-extraction-pid',type=int,action='append',default=[])
    parser.add_argument('--pages',type=int,default=120);parser.add_argument('--output',type=Path)
    main(parser.parse_args())
