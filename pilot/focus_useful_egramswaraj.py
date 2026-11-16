"""Build an audited three-distinct-work first pass without deleting source records."""
import argparse
import json
import sqlite3
import time
from collections import defaultdict
from contextlib import contextmanager
from pathlib import Path
from national_egramswaraj_details import queue,unpacked,now,YEAR
from focus_egramswaraj_collection import USEFUL_POLICY,USEFUL_LIMIT,work_name,enabled

@contextmanager
def publication_lock(work):
    import fcntl
    lock=work/'publication.lock'
    try:handle=open(lock,'rb')
    except FileNotFoundError:handle=open(lock,'a+b')
    with handle:
        fcntl.flock(handle,fcntl.LOCK_EX)
        try:yield
        finally:fcntl.flock(handle,fcntl.LOCK_UN)

def plan_scope(c,path):
    if path.exists():
        d=sqlite3.connect(path,uri=True)
        if d.execute("SELECT value FROM metadata WHERE key='planned'").fetchone():return d
        d.close();raise ValueError('Incomplete plan retained; use a new plan filename')
    d=sqlite3.connect(path,uri=True);d.execute('PRAGMA journal_mode=WAL')
    d.executescript('CREATE TABLE metadata(key TEXT PRIMARY KEY,value TEXT);CREATE TABLE defer_jobs(seq INTEGER PRIMARY KEY,id TEXT NOT NULL);')
    candidates={};reserved=defaultdict(set);scanned=0;pending=0
    indexed=c.execute("SELECT 1 FROM sqlite_master WHERE name='jobs_ready_kind'").fetchone()
    query="SELECT rowid AS queue_rowid,id,state,gp,status FROM jobs INDEXED BY jobs_ready_kind WHERE kind='activity' AND status IN ('queued','done','dispatched') ORDER BY queue_rowid" if indexed else "SELECT id,state,gp,status,context FROM jobs WHERE kind='activity' AND status IN ('queued','done','dispatched') ORDER BY rowid"
    print(json.dumps({'planning_stage':'Reading active requests through queue index'}),flush=True)
    for row in c.execute(query):
        key=(row['state'],row['gp']);status=row['status'];scanned+=1
        if status=='queued':
            pending+=1;chosen=candidates.setdefault(key,[])
            if len(chosen)>=USEFUL_LIMIT:d.execute('INSERT INTO defer_jobs(id) VALUES(?)',(row['id'],));continue
        blob=row['context'] if not indexed else c.execute('SELECT context FROM jobs WHERE rowid=?',(row['queue_rowid'],)).fetchone()[0]
        name=work_name(unpacked(blob).get('label'))
        if status!='queued':
            if name:reserved[key].add(name)
        elif not name or any(n==name for _,n in chosen):d.execute('INSERT INTO defer_jobs(id) VALUES(?)',(row['id'],))
        else:chosen.append((row['id'],name))
        if scanned%50000==0:d.commit();print(json.dumps({'planning_scanned':scanned}),flush=True)
    retained=0
    for key,chosen in candidates.items():
        names=reserved[key].copy()
        for identity,name in chosen:
            if name in names or len(names)>=USEFUL_LIMIT:d.execute('INSERT INTO defer_jobs(id) VALUES(?)',(identity,))
            else:names.add(name);retained+=1
    policy={'policy':USEFUL_POLICY,'financial_year':YEAR,'activity_details_per_gp':USEFUL_LIMIT,'selection':'First-pass distinct named works; completed and in-flight reports retained','preserve_existing':True,'payment_scope':'Latest available month with positive reported payments; future months excluded','planned_at':now(),'initial_queued_details':pending,'retained_queued_details':retained,'planned_deferrals':d.execute('SELECT count(*) FROM defer_jobs').fetchone()[0]}
    d.execute('INSERT INTO metadata VALUES(?,?)',('planned',json.dumps(policy)));d.commit()
    print(json.dumps(policy),flush=True);return d

def map_queue_rows(work,d,last):
    if d.execute("SELECT 1 FROM metadata WHERE key='row_map_ready'").fetchone():return
    print(json.dumps({'mapping_stage':'Binding retained audit IDs to current queue rows'}),flush=True)
    d.execute('PRAGMA cache_size=-262144')
    d.execute('CREATE INDEX IF NOT EXISTS defer_by_id ON defer_jobs(id)')
    d.execute('CREATE TABLE IF NOT EXISTS queue_rows(seq INTEGER PRIMARY KEY,queue_rowid INTEGER NOT NULL)')
    d.execute('ATTACH DATABASE ? AS collection',('file:'+str(work/'queue.sqlite')+'?mode=ro',))
    d.execute('PRAGMA collection.cache_size=-32768')
    d.execute('DELETE FROM queue_rows')
    d.execute("""INSERT INTO queue_rows
        SELECT p.seq,q.rowid FROM collection.jobs AS q INDEXED BY jobs_ready_kind
        CROSS JOIN main.defer_jobs AS p INDEXED BY defer_by_id
        WHERE q.status='queued' AND q.kind='activity' AND p.id=q.id AND p.seq>?""",(last,))
    d.execute("INSERT OR REPLACE INTO metadata VALUES('row_map_ready','1')");d.commit();d.execute('DETACH DATABASE collection')
    print(json.dumps({'mapped_queued_requests':d.execute('SELECT count(*) FROM queue_rows').fetchone()[0]}),flush=True)

def apply_plan(work,d,chunk=2000,guard=publication_lock):
    previous=d.execute("SELECT value FROM metadata WHERE key='last_seq'").fetchone();last=int(previous[0]) if previous else 0;moved=0;checkpoint=last
    d.execute('PRAGMA synchronous=NORMAL');map_queue_rows(work,d,last)
    c=queue(work);c.execute('PRAGMA cache_size=-65536');c.execute('PRAGMA wal_autocheckpoint=0')
    try:
        while True:
            rows=d.execute('SELECT m.seq,m.queue_rowid,p.id FROM queue_rows m JOIN defer_jobs p ON p.seq=m.seq WHERE m.seq>? ORDER BY m.seq LIMIT ?',(last,chunk)).fetchall()
            if not rows:break
            with guard(work):
                updated=c.executemany("UPDATE jobs SET status='deferred' WHERE rowid=? AND id=? AND kind='activity' AND status='queued'",[(rowid,identity) for _,rowid,identity in rows]).rowcount
                if updated<len(rows):updated+=c.executemany("UPDATE jobs SET status='deferred' WHERE id=? AND kind='activity' AND status='queued'",[(identity,) for _,_,identity in rows]).rowcount
                moved+=updated
                c.commit()
            last=rows[-1][0];d.execute("INSERT OR REPLACE INTO metadata VALUES('last_seq',?)",(str(last),));d.commit()
            if last-checkpoint>=100000:
                c.execute('PRAGMA wal_checkpoint(PASSIVE)');checkpoint=last
                print(json.dumps({'applied_through':last,'new_deferrals':moved}),flush=True)
            time.sleep(.05)
        policy=json.loads(d.execute("SELECT value FROM metadata WHERE key='planned'").fetchone()[0]);policy['activated_at']=now()
        with guard(work):
            c.execute('INSERT OR REPLACE INTO preparation VALUES(?,?)',(USEFUL_POLICY,json.dumps(policy)));c.commit()
        print(json.dumps({'scope_active':policy,'new_deferrals_this_run':moved}),flush=True)
    finally:c.close()

def main():
    p=argparse.ArgumentParser();p.add_argument('--work',type=Path,required=True);p.add_argument('--plan',type=Path,required=True);a=p.parse_args()
    c=queue(a.work)
    if not enabled(c):raise ValueError('Current-year focused collection must be enabled first')
    d=plan_scope(c,a.plan);c.close();apply_plan(a.work,d);d.close()
if __name__=='__main__':main()
