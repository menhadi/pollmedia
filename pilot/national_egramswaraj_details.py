"""Resumable nationwide eGramSwaraj voucher and activity publication.

Only exact official source identifiers create requests. Existing detailed fixtures
are retained in place. Uncertain names are published without inventing membership.
"""
import argparse
import hashlib
import html as html_lib
import json
import re
import sqlite3
import shutil
from functools import lru_cache
import zipfile
import zlib
from collections import Counter
from datetime import datetime, timezone
from decimal import Decimal, InvalidOperation
from pathlib import Path
from urllib.parse import parse_qs, urljoin, urlparse
from zoneinfo import ZoneInfo
from expand_pilibhit_panchayat_reports import Tables

YEAR = '2026-2027'
BASE = 'https://egramswaraj.gov.in/'
SCHEMA = '\nCREATE TABLE IF NOT EXISTS metadata(key TEXT PRIMARY KEY,value TEXT);\nCREATE TABLE IF NOT EXISTS records(gp TEXT,kind TEXT,record_key TEXT,label TEXT,date TEXT,amount_paise INTEGER,level TEXT,payload BLOB,source_url TEXT,source_sha256 TEXT,captured_on TEXT,PRIMARY KEY(gp,kind,record_key));\nCREATE INDEX IF NOT EXISTS record_listing ON records(gp,kind,date,record_key);\nCREATE TABLE IF NOT EXISTS versions(gp TEXT,kind TEXT,record_key TEXT,sha TEXT,payload BLOB,PRIMARY KEY(gp,kind,record_key,sha));\nCREATE TABLE IF NOT EXISTS requests(id TEXT PRIMARY KEY,gp TEXT,kind TEXT,status TEXT,url TEXT,sha TEXT,captured_on TEXT,note TEXT);\nCREATE INDEX IF NOT EXISTS request_gp ON requests(gp,kind,status);\n'

def packed(value):
    return zlib.compress(json.dumps(value,ensure_ascii=False,separators=(',',':')).encode(),6)

def unpacked(value):
    return json.loads(zlib.decompress(value))

def sha(body):
    return hashlib.sha256(body).hexdigest()

def now():
    return datetime.now(timezone.utc).isoformat()

def normalize(value):
    import unicodedata
    return ''.join(c for c in unicodedata.normalize('NFKC',str(value).replace('&','and')).casefold() if c.isalnum())

@lru_cache(maxsize=1)
def state_names():
    p=Path(__file__).resolve().parents[1]/'application/database/fixtures/egramswaraj-national/index.json'
    return {s:e['name'] for s,e in json.loads(p.read_text())['states'].items()} if p.exists() else {}

def connect(path):
    path.parent.mkdir(parents=True,exist_ok=True)
    c=sqlite3.connect(path,timeout=60);c.row_factory=sqlite3.Row
    c.execute('PRAGMA journal_mode=WAL');c.execute('PRAGMA synchronous=NORMAL')
    return c

def queue(work):
    c=connect(work/'queue.sqlite')
    c.executescript("\nCREATE TABLE IF NOT EXISTS gps(state TEXT,gp TEXT,name TEXT,legacy INTEGER DEFAULT 0,PRIMARY KEY(state,gp));\nCREATE TABLE IF NOT EXISTS jobs(id TEXT PRIMARY KEY,state TEXT,gp TEXT,kind TEXT,url TEXT,context BLOB,status TEXT DEFAULT 'queued',batch TEXT,error TEXT,captured_on TEXT,source_sha256 TEXT);\nCREATE INDEX IF NOT EXISTS jobs_dispatch ON jobs(status,kind,state,id);\nCREATE INDEX IF NOT EXISTS jobs_gp ON jobs(state,gp,status);\nCREATE INDEX IF NOT EXISTS jobs_state ON jobs(state,status);\nCREATE TABLE IF NOT EXISTS archives(name TEXT PRIMARY KEY,sha TEXT);\nCREATE TABLE IF NOT EXISTS preparation(name TEXT PRIMARY KEY,value TEXT);\n")
    return c

def store(root,state):
    if not re.fullmatch(r'[1-9][0-9]?',str(state)):
        raise ValueError('Invalid state')
    c=connect(root/('state-'+str(state)+'.sqlite'));c.executescript(SCHEMA)
    for key,value in {'schema':'1','state':str(state),'financial_year':YEAR,'encoding':'zlib-json'}.items():
        c.execute('INSERT OR IGNORE INTO metadata VALUES(?,?)',(key,value))
    return c

def add_job(c,state,gp,kind,url,context):
    u=urlparse(url)
    if u.scheme!='https' or u.netloc!='egramswaraj.gov.in':
        raise ValueError('Non-official source URL')
    identity=sha((kind+'|'+state+'|'+gp+'|'+url).encode())
    c.execute('INSERT OR IGNORE INTO jobs(id,state,gp,kind,url,context) VALUES(?,?,?,?,?,?)',(identity,state,gp,kind,url,packed(context)))
    return identity

def record(db,gp,kind,key,value,source,level='complete'):
    old=db.execute('SELECT payload,source_sha256,level FROM records WHERE gp=? AND kind=? AND record_key=?',(gp,kind,key)).fetchone()
    if old and old['level']=='complete' and level!='complete':return
    if old and old['payload']!=packed(value):
        db.execute('INSERT OR IGNORE INTO versions VALUES(?,?,?,?,?)',(gp,kind,key,old['source_sha256'],old['payload']))
    counter = kind+'_'+level if kind=='activities' else kind
    previous_counter = kind+'_'+old['level'] if old and kind=='activities' else kind
    for counter_key,delta in ([(counter,1)] if old is None else ([(previous_counter,-1),(counter,1)] if counter!=previous_counter else [])):
        db.execute("INSERT INTO metadata(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=CAST(value AS INTEGER)+excluded.value",('count:'+counter_key,str(delta)))
    db.execute('INSERT OR REPLACE INTO records VALUES(?,?,?,?,?,?,?,?,?,?,?)',(gp,kind,key,value.get('name') or value.get('voucher') or key,value.get('date'),value.get('amount_paise'),level,packed(value),source['url'],source['sha'],source['day']))

def legacy_codes(fixtures):
    result=set()
    for p in (fixtures/'egramswaraj-details').rglob('manifest.json'):
        for row in json.loads(p.read_text()).get('reports',[]):
            result.add((str(row['state_lgd']),str(row['panchayat_code'])))
    for row in json.loads((fixtures/'egramswaraj-accounts.json').read_text()).get('reports',[]):
        if row.get('activities') or any(row.get(k) for k in ['payments','receipts','contra','journal']):
            result.add((str(row.get('mapping',{}).get('state_lgd','9')),str(row['panchayat_code'])))
    return result

def archive_metadata(z):
    result={}
    for name in z.namelist():
        if name.startswith('_capture-') and name.endswith('.jsonl'):
            for line in z.read(name).decode('utf-8-sig').splitlines():
                row=json.loads(line);result[row['file']]=row
    return result

def prepare(work,summaries,fixtures):
    c=queue(work);index=json.loads((fixtures/'egramswaraj-national/index.json').read_text());legacy=legacy_codes(fixtures)
    known={};counts={}
    for state,e in index['states'].items():
        if 'file' not in e:continue
        db=sqlite3.connect('file:'+str(fixtures/'egramswaraj-national'/e['file'])+'?mode=ro',uri=True)
        for gp,name,blob in db.execute('SELECT gp,name,report_blob FROM panchayats'):
            known[(state,gp)]=name
            c.execute('INSERT OR IGNORE INTO gps VALUES(?,?,?,?)',(state,gp,name,int((state,gp) in legacy)))
            if blob:
                report=unpacked(blob)
                counts[(state,gp)]={r['month'] for r in report.get('monthly',[]) if any(not isinstance(r.get(k),int) or r[k]>0 for k in ['payments','receipts','contra','journal'])}
        db.close()
    c.commit();print(json.dumps({'registered':len(known),'existing_detailed_preserved':len(legacy)}),flush=True)
    for p in sorted(set(summaries.glob('national-plans-*.zip'))|set(summaries.glob('national-topology-*.zip'))):
        marker='catalog:'+p.name
        if c.execute('SELECT 1 FROM preparation WHERE name=?',(marker,)).fetchone():continue
        with zipfile.ZipFile(p) as z:
            metas=archive_metadata(z)
            for name,meta in metas.items():
                m=re.fullmatch(r'official-plans-(\d+)-(\d+)-(\d+)\.json',name)
                t=re.fullmatch(r'official-topology-(\d+)\.json',name)
                if not m and not t:continue
                state=m[1] if m else t[1];query=parse_qs(urlparse(meta['url']).query)
                if query.get('stateCd')!=[state] or query.get('planYr')!=['2026']:raise ValueError('Catalog URL mismatch')
                if m and (query.get('zpCd')!=[m[2]] or query.get('bpCd')!=[m[3]]):raise ValueError('Plan hierarchy mismatch')
                raw=z.read(name)
                for row in json.loads(raw):
                    gp=str(row.get('gp_cd') or '');plan=str(row.get('plan_cd') or '')
                    if (state,gp) not in known or (state,gp) in legacy or not plan.isdigit() or int(plan)<=0:continue
                    if m and (str(row.get('zp_cd'))!=m[2] or str(row.get('parent_cd') or 0)!=m[3]):raise ValueError('Plan row hierarchy mismatch')
                    context=dict(row,state=state,gp=gp,plan=plan,membership_catalog_url=meta['url'],membership_catalog_sha256=sha(raw))
                    add_job(c,state,gp,'plan',BASE+'getActivityList.do?planCd='+plan+'&activityStts=0',context)
        c.execute('INSERT INTO preparation VALUES(?,?)',(marker,now()));c.commit()
    print('Plan jobs prepared',c.execute('SELECT count(*) FROM jobs').fetchone()[0],flush=True)
    # Use links as published by the source, never infer a district/block namespace.
    for p in sorted(set(summaries.glob('national-monthly-*.zip'))|set(summaries.glob('national-extra-monthly-*.zip'))):
        marker='monthly:'+p.name
        if c.execute('SELECT 1 FROM preparation WHERE name=?',(marker,)).fetchone():continue
        with zipfile.ZipFile(p) as z:
            metas=archive_metadata(z)
            for name,meta in metas.items():
                m=re.fullmatch(r'official-monthly-(\d+)-(\d+)\.html',name)
                if not m:continue
                state,gp=m.groups()
                if (state,gp) not in known or (state,gp) in legacy or not counts.get((state,gp)):continue
                expected=BASE+'FileRedirect.jsp?FD=FinancialYear2026-2027/'+state+'&name='+gp+'.html'
                if meta['url']!=expected:raise ValueError('Monthly capture URL mismatch')
                raw=z.read(name)
                for link in re.findall(r"href\s*=\s*['\"]([^'\"]*voucherWiseReport\.do\?[^'\"]+)['\"]",raw.decode('utf-8-sig',errors='replace'),re.I):
                    url=urljoin(BASE,html_lib.unescape(link));q=parse_qs(urlparse(url).query)
                    if q.get('state')!=[state] or q.get('village')!=[gp] or q.get('finYear')!=[YEAR]:raise ValueError('Voucher link geography mismatch')
                    month=q.get('month',[''])[0]
                    if not month.isdigit() or not 1<=int(month)<=12:continue
                    period=('2027' if int(month)<4 else '2026')+'-'+month.zfill(2)
                    if period not in counts[(state,gp)]:continue
                    add_job(c,state,gp,'voucher',url,{'state':state,'gp':gp,'month':period,'monthly_url':expected,'monthly_sha256':sha(raw)})
        c.execute('INSERT INTO preparation VALUES(?,?)',(marker,now()));c.commit()
        print('Prepared',p.name,flush=True)
    c.execute("INSERT OR REPLACE INTO preparation VALUES('complete',?)",(now(),));c.commit();c.close()

def table_document(raw):
    d=Tables();d.feed(raw.decode('utf-8-sig',errors='replace'));d.close();d.body=' '.join(' '.join(d.text).split());return d

def amount(text):
    s=text.replace(',','').strip()
    if not re.fullmatch(r'[0-9]+(?:\.[0-9]{1,2})?',s):raise ValueError('Unrecognised rupee amount')
    return int(Decimal(s)*100)

def vouchers(raw,context):
    doc=table_document(raw)
    if 'Voucher Wise Summary Report' not in doc.body or 'Voucher Amount (in Rs.)' not in doc.body:raise ValueError('Source did not return a rupee voucher report')
    table=next((t for t in doc.tables if any([c['text'] for c in r][:2]==['Receipts','Payments'] for r in t)),None)
    if table is None:raise ValueError('Voucher table unavailable')
    reported=None
    m=re.search(r'on [A-Za-z]+, ([0-9]{1,2} [A-Za-z]+ [0-9]{4})',doc.body)
    if m:
        for fmt in ['%d %b %Y','%d %B %Y']:
            try:reported=datetime.strptime(m[1],fmt).date().isoformat();break
            except ValueError:pass
    result=[]
    for n,cells in enumerate(table):
        if len(cells)!=15:continue
        for kind,di,vi,ti,ai in [('receipts',0,1,2,3),('payments',4,5,6,7),('contra',9,10,None,11),('journal',12,13,None,14)]:
            number=cells[vi]['text'];date=cells[di]['text']
            if (not number and not date) or date=='Voucher Date':continue
            try:date=datetime.strptime(date,'%d/%m/%Y').date().isoformat()
            except ValueError:raise ValueError('Unreadable voucher date; original source retained')
            notes=[]
            if date[:7]!=context['month']:notes.append('The source voucher date differs from the requested month; original date retained.')
            if reported and date>reported:notes.append('Voucher date is later than the source report date.')
            if context.get('captured_on') and date>context['captured_on']:notes.append('Voucher date is later than retrieval; the original source date is retained.')
            if reported and context.get('captured_on') and reported>context['captured_on']:notes.append('Source report date is later than retrieval; both original dates are retained.')
            detail=None;identifier=None
            expected={'payments':'/paymentVoucherDetail.do','receipts':'/receiptVoucherDetail.do'}.get(kind)
            if expected:
                links=cells[vi]['links']
                if len(links)!=1:
                    notes.append('No unique individual voucher reference was supplied; the source report row is retained.')
                    links=[]
                detail=links[0] if links else None;u=urlparse(detail or '');q=parse_qs(u.query)
                if detail:
                    if u.scheme!='https' or u.netloc!='egramswaraj.gov.in' or u.path!=expected or q.get('PRIEntity_code')!=[context['gp']] or q.get('stateCode')!=[context['state']] or q.get('finYear')!=[YEAR] or not q.get('voucherID',[''])[0].isdigit():raise ValueError('Voucher detail reference points to another Panchayat/state/year')
                    identifier=q['voucherID'][0]
            value={'kind':kind,'date':date,'voucher':number,'voucher_type':cells[ti]['text'] if ti is not None else None,'amount_paise':amount(cells[ai]['text']),'unit':'INR','detail_url':detail,'report_date':reported,'notes':notes,'source_row':n+1,'month':context['month']}
            key=identifier or sha(json.dumps([kind,date,number,value['amount_paise'],context['month'],n]).encode())
            result.append((kind,key,value))
    return result

def unavailable_detail(db,gp,key,note):
    row=db.execute("SELECT payload FROM records WHERE gp=? AND kind='activities' AND record_key=? AND level='listed'",(gp,key)).fetchone()
    if row:
        value=unpacked(row['payload']);value['status']='Details unavailable'
        if note not in value.setdefault('notes',[]):value['notes'].append(note)
        db.execute("UPDATE records SET payload=? WHERE gp=? AND kind='activities' AND record_key=? AND level='listed'",(packed(value),gp,key))

def consume(c,db,job,raw,meta):
    context=unpacked(job['context']);day=datetime.fromisoformat(re.sub(r'(\.\d{6})\d+',r'\1',meta['captured_at']).replace('Z','+00:00')).astimezone(ZoneInfo('Asia/Kolkata')).date().isoformat()
    source={'url':job['url'],'sha':sha(raw),'day':day};gp=job['gp'];kind=job['kind'];note=None
    if kind=='voucher':
        for record_kind,key,value in vouchers(raw,dict(context,captured_on=source['day'])):record(db,gp,record_kind,key,value,source)
    elif kind=='plan':
        labels=json.loads(raw.decode('utf-8-sig'))
        if not isinstance(labels,list) or any(not isinstance(v,str) for v in labels):raise ValueError('Unreadable official activity membership list')
        for label in labels:
            m=re.fullmatch(r'(.*?)\s*\(([0-9]+)\)\s*',label)
            if not m:raise ValueError('Unrecognised activity membership identifier')
            name,activity=m.groups();ctx=dict(context,activity=activity,label=name.strip(),plan_membership_url=job['url'],plan_membership_sha256=source['sha'])
            add_job(c,job['state'],gp,'activity',BASE+'activitySectionData.do?activityCd='+activity,ctx)
            record(db,gp,'activities',activity,{'name':name.strip(),'status':'Details queued','plan':context['plan'],'notes':[]},source,'listed')
    elif kind=='activity':
        data=json.loads(raw.decode('utf-8-sig'),parse_float=Decimal)
        if not isinstance(data,dict) or str(data.get('activityCd'))!=context['activity'] or str(data.get('plnUntCd'))!=gp or str(data.get('fnclYr'))!='2026':raise ValueError('Activity source identifier/Panchayat/year differs; no record assigned')
        expected_state=context.get('state_name') or state_names().get(job['state'])
        if data.get('stateName') and expected_state and normalize(str(data['stateName']).removeprefix('The '))!=normalize(str(expected_state).removeprefix('The ')):
            raise ValueError('Activity source state differs; original source retained without assigning it to this state')
        notes=[]
        if normalize(data.get('activityName',''))!=normalize(context['label']):notes.append('Activity name differs from its plan list; source names retained.')
        value={'name':data.get('activityName') or context['label'],'status':data.get('activityStts'),'description':data.get('activityDesc'),'focus_area':data.get('focusArea'),'theme':data.get('themeName'),'type':data.get('activityType'),'output':data.get('outputTyp'),'reported_cost':str(data['totalCost']) if data.get('totalCost') is not None else None,'cost_unit':None,'plan':context['plan'],'plan_type':context.get('plantype'),'plan_membership_url':context['plan_membership_url'],'plan_membership_sha256':context['plan_membership_sha256'],'notes':notes,'original':json.loads(raw.decode('utf-8-sig'))}
        record(db,gp,'activities',context['activity'],value,source)
    return source,note

def ingest(work,root,archive):
    if shutil.disk_usage(work).free<8*1024**3:raise ValueError('Server disk reserve reached; raw archive retained for later ingestion')
    c=queue(work);digest=sha(archive.read_bytes());old=c.execute('SELECT sha FROM archives WHERE name=?',(archive.name,)).fetchone()
    if old:
        if old['sha']!=digest:raise ValueError('Previously ingested archive changed')
        print(json.dumps({'archive':archive.name,'already_ingested':True}));return
    stores={};changed=Counter()
    with zipfile.ZipFile(archive) as z:
        metas=archive_metadata(z)
        for filename,meta in metas.items():
            m=re.fullmatch(r'detail-([a-f0-9]{64})\.(?:html|json)',filename)
            if not m:raise ValueError('Unknown capture filename')
            job=c.execute('SELECT * FROM jobs WHERE id=?',(m[1],)).fetchone()
            if job is None or meta['url']!=job['url']:raise ValueError('Captured response not bound to a queued official URL')
            if job['status'] in ['done','note']:continue
            db=stores.get(job['state'])
            if db is None:db=store(root,job['state']);stores[job['state']]=db
            raw=z.read(filename);status='done';note=None
            db.execute('SAVEPOINT record_response')
            try:source,note=consume(c,db,job,raw,meta)
            except (ValueError,KeyError,TypeError,InvalidOperation) as e:
                db.execute('ROLLBACK TO record_response');status='note';note=str(e)
                source={'url':job['url'],'sha':sha(raw),'day':meta['captured_at'][:10]}
            db.execute('RELEASE record_response')
            if status=='note' and job['kind']=='activity':unavailable_detail(db,job['gp'],unpacked(job['context'])['activity'],note)
            db.execute('INSERT OR REPLACE INTO requests VALUES(?,?,?,?,?,?,?,?)',(job['id'],job['gp'],job['kind'],status,job['url'],source['sha'],source['day'],note))
            c.execute('UPDATE jobs SET status=?,captured_on=?,source_sha256=?,error=? WHERE id=?',(status,source['day'],source['sha'],note,job['id']));changed[status]+=1
    for db in stores.values():db.commit();db.close()
    c.execute('INSERT INTO archives VALUES(?,?)',(archive.name,digest));c.commit();c.close();print(json.dumps({'archive':archive.name,'processed':dict(changed)}),flush=True)

def next_batch(work,limit):
    if shutil.disk_usage(work).free<10*1024**3:raise ValueError('Server disk reserve reached; collection paused before requesting more records')
    c=queue(work);batch='batch-'+datetime.now(timezone.utc).strftime('%Y%m%dT%H%M%S%f');rows=[]
    for kind,fraction in [('voucher',.5),('plan',.25),('activity',.25)]:
        target=max(1,int(limit*fraction))
        states=[r[0] for r in c.execute("SELECT DISTINCT state FROM jobs WHERE status='queued' AND kind=?",(kind,))]
        quota=max(1,target//max(1,len(states)))
        for state in states:
            rows.extend(c.execute("SELECT * FROM jobs WHERE status='queued' AND kind=? AND state=? ORDER BY gp LIMIT ?",(kind,state,quota)).fetchall())
    if len(rows)<limit:
        chosen={r['id'] for r in rows}
        for r in c.execute("SELECT * FROM jobs WHERE status='queued' ORDER BY CASE kind WHEN 'activity' THEN 0 ELSE 1 END,gp,state LIMIT ?",(limit*2,)):
            if r['id'] not in chosen:rows.append(r)
            if len(rows)>=limit:break
    requests=[{'file':'detail-'+r['id']+('.html' if r['kind']=='voucher' else '.json'),'url':r['url']} for r in rows]
    path=work/'outgoing'/(batch+'.json');path.parent.mkdir(exist_ok=True);path.write_text(json.dumps(requests))
    c.executemany("UPDATE jobs SET status='dispatched',batch=? WHERE id=?",[(batch,r['id']) for r in rows]);c.commit()
    prepared=bool(c.execute("SELECT 1 FROM preparation WHERE name='complete'").fetchone());pending=c.execute("SELECT count(*) FROM jobs WHERE status IN ('queued','dispatched')").fetchone()[0]
    print(json.dumps({'batch':batch,'manifest':str(path),'requests':len(rows),'pending':pending,'preparation_complete':prepared}));c.close()

def finish(work,root,result):
    c=queue(work);stores={};bad=0
    for row in json.loads(result.read_text(encoding='utf-8-sig')):
        if row['ok']:continue
        m=re.fullmatch(r'detail-([a-f0-9]{64})\.(?:html|json)',row['file'])
        if not m:raise ValueError('Invalid failure identity')
        j=c.execute('SELECT * FROM jobs WHERE id=?',(m[1],)).fetchone()
        if not j or j['status'] in ['done','note']:continue
        note='Official report could not be retrieved after retries; its source link is retained.';day=datetime.now(ZoneInfo('Asia/Kolkata')).date().isoformat()
        db=stores.get(j['state'])
        if db is None:db=store(root,j['state']);stores[j['state']]=db
        if j['kind']=='activity':unavailable_detail(db,j['gp'],unpacked(j['context'])['activity'],note)
        db.execute('INSERT OR REPLACE INTO requests VALUES(?,?,?,?,?,?,?,?)',(j['id'],j['gp'],j['kind'],'note',j['url'],None,day,note))
        c.execute("UPDATE jobs SET status='note',error=?,captured_on=? WHERE id=?",(note,day,j['id']));bad+=1
    for db in stores.values():db.commit();db.close()
    c.commit();c.close();print(json.dumps({'retrieval_notes':bad}))

def publish(work,root,fixtures):
    root.mkdir(parents=True,exist_ok=True);c=queue(work);names=json.loads((fixtures/'egramswaraj-national/index.json').read_text())['states'];states={};totals=Counter()
    for state,entry in names.items():
        jobs={r[0]:r[1] for r in c.execute('SELECT status,count(*) FROM jobs WHERE state=? GROUP BY status',(state,))}
        counts={};path=root/('state-'+state+'.sqlite')
        if path.exists():
            db=store(root,state)
            counts={r[0][6:]:int(r[1]) for r in db.execute("SELECT key,value FROM metadata WHERE key LIKE 'count:%'")};db.commit();db.close()
        for k,v in counts.items():totals[k]+=v
        legacy=c.execute('SELECT count(*) FROM gps WHERE state=? AND legacy=1',(state,)).fetchone()[0]
        states[state]={'name':entry['name'],'jobs':jobs,'records':counts,'existing_detailed_retained':legacy}
    prepared=bool(c.execute("SELECT 1 FROM preparation WHERE name='complete'").fetchone());jobs={r[0]:r[1] for r in c.execute('SELECT status,count(*) FROM jobs GROUP BY status')};c.close()
    index={'schema':1,'publication_enabled':True,'financial_year':YEAR,'updated_at':now(),'preparation_complete':prepared,'jobs':jobs,'records':dict(totals),'states':states}
    temp=root/'.index.json.tmp';temp.write_text(json.dumps(index,ensure_ascii=False,separators=(',',':')));temp.replace(root/'index.json')
    print(json.dumps({'jobs':jobs,'records':dict(totals),'preparation_complete':prepared}),flush=True)

def main():
    a=argparse.ArgumentParser();a.add_argument('action',choices=['prepare','next','ingest','finish','publish']);a.add_argument('--work',type=Path,required=True);a.add_argument('--root',type=Path,default=Path('application/storage/app/egramswaraj-national-details'));a.add_argument('--summaries',type=Path,default=Path('/home/pollmedia/tmp/national-egs-summary-20261114'));a.add_argument('--fixtures',type=Path,default=Path('application/database/fixtures'));a.add_argument('--archive',type=Path);a.add_argument('--result',type=Path);a.add_argument('--limit',type=int,default=2000);args=a.parse_args();args.work.mkdir(parents=True,exist_ok=True)
    if args.action=='prepare':prepare(args.work,args.summaries,args.fixtures)
    elif args.action=='next':next_batch(args.work,args.limit)
    elif args.action=='ingest':ingest(args.work,args.root,args.archive)
    elif args.action=='finish':finish(args.work,args.root,args.result)
    else:publish(args.work,args.root,args.fixtures)
if __name__=='__main__':main()
