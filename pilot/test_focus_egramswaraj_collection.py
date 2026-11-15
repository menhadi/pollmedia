import io
import json
import sqlite3
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path
from national_egramswaraj_details import queue,packed,add_job,optimize,next_batch,consume,store
from focus_egramswaraj_collection import activate,enabled,ACTIVITY_LIMIT

class FocusTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name);self.work=self.root/'work';self.work.mkdir();self.c=queue(self.work)
        self.fixtures=self.root/'fixtures';f=self.fixtures/'egramswaraj-national';f.mkdir(parents=True)
        (f/'index.json').write_text(json.dumps({'states':{'3':{'file':'3.sqlite'}}}))
        db=sqlite3.connect(f/'3.sqlite');db.execute('CREATE TABLE panchayats(gp TEXT,report_blob BLOB)')
        for gp,monthly in [('1',[{'month':'2026-04','payments':4},{'month':'2026-10','payments':2},{'month':'2026-11','payments':0},{'month':'2026-12','payments':8}]),('2',[{'month':'2026-10','payments':0,'receipts':5}])]:
            db.execute('INSERT INTO panchayats VALUES(?,?)',(gp,packed({'financial_year':'2026-2027','monthly':monthly})))
        db.commit();db.close()
    def tearDown(self):self.c.close();self.tmp.cleanup()
    def add(self,gp,kind,suffix,context):
        return add_job(self.c,'3',gp,kind,'https://egramswaraj.gov.in/'+suffix,dict(context,state='3',gp=gp))
    def test_focus_preserves_done_and_defers_older_receipt_only_future_reports(self):
        ids={m:self.add('1','voucher','voucherWiseReport.do?month='+m,{'month':'2026-'+m}) for m in ['04','10','11','12']}
        self.c.execute("UPDATE jobs SET status='done',source_sha256='original' WHERE id=?",(ids['04'],))
        zero=self.add('2','voucher','voucherWiseReport.do?month=10',{'month':'2026-10'})
        policy=activate(self.c,self.fixtures,'2026-2027','2026-11-15');self.c.commit()
        states={r['id']:r['status'] for r in self.c.execute('SELECT * FROM jobs')}
        self.assertEqual('done',states[ids['04']]);self.assertEqual('queued',states[ids['10']]);self.assertEqual('deferred',states[ids['11']]);self.assertEqual('deferred',states[ids['12']]);self.assertEqual('deferred',states[zero])
        self.assertEqual('original',self.c.execute('SELECT source_sha256 FROM jobs WHERE id=?',(ids['04'],)).fetchone()[0]);self.assertTrue(enabled(self.c));self.assertTrue(activate(self.c,self.fixtures,'2026-2027')['already_active'])
    def test_activity_limit_keeps_all_listings_and_completed_records_without_duplicates(self):
        ids=[self.add('1','activity','activitySectionData.do?activityCd='+str(n),{'activity':str(n),'label':'Road'}) for n in range(15)]
        self.c.execute("UPDATE jobs SET status='done' WHERE id=?",(ids[-1],));activate(self.c,self.fixtures,'2026-2027','2026-11-15');self.c.commit()
        self.assertEqual(ACTIVITY_LIMIT,self.c.execute("SELECT count(*) FROM jobs WHERE kind='activity' AND status!='deferred'").fetchone()[0]);self.assertEqual('done',self.c.execute('SELECT status FROM jobs WHERE id=?',(ids[-1],)).fetchone()[0])
        plan=self.add('1','plan','getActivityList.do?planCd=88',{'plan':'88'});job=self.c.execute('SELECT * FROM jobs WHERE id=?',(plan,)).fetchone();db=store(self.root/'records','3')
        raw=json.dumps(['Road repair (99)','Water supply (100)']).encode()
        consume(self.c,db,job,raw,{'captured_at':'2026-11-15T10:00:00Z'});consume(self.c,db,job,raw,{'captured_at':'2026-11-15T10:00:00Z'})
        self.assertEqual(2,db.execute('SELECT count(*) FROM records').fetchone()[0]);self.assertEqual(2,self.c.execute("SELECT count(*) FROM jobs WHERE kind='activity' AND url IN ('https://egramswaraj.gov.in/activitySectionData.do?activityCd=99','https://egramswaraj.gov.in/activitySectionData.do?activityCd=100') AND status='deferred'").fetchone()[0]);db.close()
    def test_deferred_failure_remains_deferred_without_unavailable_verdict(self):
        from national_egramswaraj_details import finish
        key=self.add('1','voucher','voucherWiseReport.do?month=4',{'month':'2026-04'})
        activate(self.c,self.fixtures,'2026-2027','2026-11-15');self.c.commit()
        result=self.work/'failed.json';result.write_text(json.dumps([{'file':'detail-'+key+'.html','ok':False,'status':404}]))
        finish(self.work,self.root/'records',result)
        self.assertEqual('deferred',self.c.execute('SELECT status FROM jobs WHERE id=?',(key,)).fetchone()[0])
    def test_plan_discovery_is_not_starved_by_large_detail_backlog(self):
        optimize(self.work);activate(self.c,self.fixtures,'2026-2027','2026-11-15')
        for n in range(30):self.add(str(n+10),'activity','activitySectionData.do?activityCd='+str(n),{'activity':str(n)})
        for n in range(30):self.add(str(n+10),'plan','getActivityList.do?planCd='+str(n),{'plan':str(n)})
        self.c.commit();out=io.StringIO()
        with redirect_stdout(out):next_batch(self.work,20,'activities')
        self.assertEqual(20,json.loads(out.getvalue())['requests']);self.assertEqual(16,self.c.execute("SELECT count(*) FROM jobs WHERE kind='plan' AND status='dispatched'").fetchone()[0])
        counts={(r[0],r[1],r[2]):r[3] for r in self.c.execute('SELECT state,kind,status,count(*) FROM jobs GROUP BY state,kind,status')};self.assertEqual(counts,{(r[0],r[1],r[2]):r[3] for r in self.c.execute('SELECT * FROM job_counts WHERE total>0')})
if __name__=='__main__':unittest.main()
