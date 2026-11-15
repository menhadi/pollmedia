"""Coverage-first collection policy; never removes published records or queued evidence."""
import json
import re
import sqlite3
import zlib
from datetime import datetime
from zoneinfo import ZoneInfo

POLICY='focused-current-year-v1'
ACTIVITY_LIMIT=10
DEFERRED_NOTE='Retained for later collection; outside the initial current-year coverage scope.'

def enabled(c):
    return c.execute("SELECT 1 FROM preparation WHERE name=?",(POLICY,)).fetchone() is not None

def apply_new_job(c,identity,state,gp,kind,context):
    if not enabled(c):return
    defer=False
    if kind=='activity':
        count=c.execute("SELECT count(*) FROM jobs WHERE state=? AND gp=? AND kind='activity' AND status!='deferred'",(state,gp)).fetchone()[0]
        defer=count>ACTIVITY_LIMIT
    elif kind=='voucher':
        row=c.execute('SELECT payment_month FROM focus_gp WHERE state=? AND gp=?',(state,gp)).fetchone()
        defer=row is None or row[0]!=context.get('month')
    if defer:c.execute("UPDATE jobs SET status='deferred',error=? WHERE id=? AND status='queued'",(DEFERRED_NOTE,identity))

def activate(c,fixtures,year,as_of=None):
    if enabled(c):return {'policy':POLICY,'already_active':True}
    today=as_of or datetime.now(ZoneInfo('Asia/Kolkata')).date().isoformat()
    c.execute('CREATE TABLE IF NOT EXISTS focus_gp(state TEXT,gp TEXT,payment_month TEXT,PRIMARY KEY(state,gp))')
    targets=[]
    index=json.loads((fixtures/'egramswaraj-national/index.json').read_text())
    for state,entry in index['states'].items():
        if 'file' not in entry:continue
        db=sqlite3.connect('file:'+str(fixtures/'egramswaraj-national'/entry['file'])+'?mode=ro',uri=True)
        for gp,blob in db.execute('SELECT gp,report_blob FROM panchayats WHERE report_blob IS NOT NULL'):
            report=json.loads(zlib.decompress(blob))
            if report.get('financial_year')!=year:continue
            months=[]
            for row in report.get('monthly',[]):
                month=row.get('month','');payments=row.get('payments')
                if not re.fullmatch(r'[0-9]{4}-(0[1-9]|1[0-2])',month) or month>today[:7]:continue
                if month<year[:4]+'-04' or month>year[-4:]+'-03':continue
                if type(payments) is int and payments>0:months.append(month)
            targets.append((str(state),str(gp),max(months) if months else None))
        db.close()
    c.executemany('INSERT OR REPLACE INTO focus_gp VALUES(?,?,?)',targets)
    # Month comes from the verified request context, rather than a guessed URL or geography.
    selections=[]
    selected={(s,g):m for s,g,m in targets}
    for row in c.execute("SELECT id,state,gp,context FROM jobs WHERE kind='voucher' AND status IN ('queued','dispatched')"):
        context=json.loads(zlib.decompress(row['context']))
        if selected.get((row['state'],row['gp']))!=context.get('month'):selections.append((DEFERRED_NOTE,row['id']))
    c.executemany("UPDATE jobs SET status='deferred',batch=NULL,error=? WHERE id=?",selections)
    c.execute("""CREATE TEMP TABLE focus_activity_ids AS
        SELECT id FROM (SELECT id,status,row_number() OVER(PARTITION BY state,gp ORDER BY CASE WHEN status IN ('done','note') THEN 0 ELSE 1 END,id) AS rank
        FROM jobs WHERE kind='activity') WHERE rank<=?""",(ACTIVITY_LIMIT,))
    c.execute('CREATE UNIQUE INDEX temp.focus_activity_key ON focus_activity_ids(id)')
    c.execute("UPDATE jobs SET status='deferred',batch=NULL,error=? WHERE kind='activity' AND status IN ('queued','dispatched') AND id NOT IN(SELECT id FROM focus_activity_ids)",(DEFERRED_NOTE,))
    c.execute('DROP TABLE focus_activity_ids')
    policy={'policy':POLICY,'financial_year':year,'activated_on':today,'activity_details_per_gp':ACTIVITY_LIMIT,'payment_scope':'Latest available month with positive reported payments; future months excluded','preserve_existing':True}
    c.execute('INSERT INTO preparation VALUES(?,?)',(POLICY,json.dumps(policy)))
    return policy
