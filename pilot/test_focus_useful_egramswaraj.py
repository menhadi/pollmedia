import json
import tempfile
import unittest
from pathlib import Path
from national_egramswaraj_details import queue,add_job,store,record,unpacked,optimize
from focus_egramswaraj_collection import work_name,USEFUL_POLICY
from focus_useful_egramswaraj import plan_scope,apply_plan

class UsefulWorkTest(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.root=Path(self.tmp.name);self.c=queue(self.root);self.c.commit();optimize(self.root)
    def tearDown(self):self.c.close();self.tmp.cleanup()
    def add(self,gp,number,label,status='queued'):
        key=add_job(self.c,'9',gp,'activity','https://egramswaraj.gov.in/activitySectionData.do?activityCd='+str(number),{'label':label,'activity':str(number)})
        self.c.execute('UPDATE jobs SET source_sha256=? WHERE id=?',('original-evidence',key))
        if status!='queued':self.c.execute('UPDATE jobs SET status=? WHERE id=?',(status,key))
        return key
    def test_keeps_three_distinct_names_and_preserves_completed_inflight_and_source(self):
        self.c.commit();optimize(self.root)
        a=self.add('1',1,'Road repair','done');dup=self.add('1',2,'ROAD-REPAIR');b=self.add('1',3,'Water supply');dup2=self.add('1',4,'Water supply!');c=self.add('1',5,'Drainage');extra=self.add('1',6,'School building')
        active=self.add('2',7,'In-flight road','dispatched');others=[self.add('2',n,'Completed '+str(n),'done') for n in range(8,12)];oldextra=self.add('2',12,'Another work')
        hindi=[self.add('3',20+n,name) for n,name in enumerate(['सड़क निर्माण','सड़क निर्माण!','पेयजल कार्य','नाली निर्माण','स्कूल कार्य'])]
        code=self.add('4',30,'12345');self.c.commit()
        db=store(self.root/'records','9');record(db,'1','activities','1',{'name':'Road repair','reported_cost':'12.34'},{'url':'https://egramswaraj.gov.in/activitySectionData.do?activityCd=1','sha':'original','day':'2026-11-16'});db.commit();before=db.execute('SELECT payload,source_sha256 FROM records').fetchone()
        plan=plan_scope(self.c,self.root/'plan.sqlite');apply_plan(self.root,plan,chunk=2)
        statuses={row['id']:row['status'] for row in self.c.execute('SELECT id,status FROM jobs')}
        self.assertEqual('done',statuses[a]);self.assertEqual('dispatched',statuses[active]);self.assertTrue(all(statuses[k]=='done' for k in others));self.assertEqual('queued',statuses[b]);self.assertEqual('queued',statuses[c])
        self.assertTrue(all(statuses[k]=='deferred' for k in [dup,dup2,extra,oldextra,code,hindi[1],hindi[4]]));self.assertTrue(all(statuses[hindi[n]]=='queued' for n in [0,2,3]))
        self.assertEqual(tuple(before),tuple(db.execute('SELECT payload,source_sha256 FROM records').fetchone()));self.assertEqual('original-evidence',self.c.execute('SELECT source_sha256 FROM jobs WHERE id=?',(extra,)).fetchone()[0])
        policy=json.loads(self.c.execute('SELECT value FROM preparation WHERE name=?',(USEFUL_POLICY,)).fetchone()[0]);self.assertEqual(3,policy['activity_details_per_gp'])
        apply_plan(self.root,plan,chunk=2);self.assertEqual(statuses,{row['id']:row['status'] for row in self.c.execute('SELECT id,status FROM jobs')});actual={(x[0],x[1],x[2]):x[3] for x in self.c.execute('SELECT state,kind,status,count(*) FROM jobs GROUP BY state,kind,status')};self.assertEqual(actual,{(x[0],x[1],x[2]):x[3] for x in self.c.execute('SELECT * FROM job_counts WHERE total>0')});plan.close();db.close()
    def test_captured_request_is_not_cancelled_if_it_started_after_planning(self):
        ids=[self.add('1',n,'Work '+str(n)) for n in range(1,5)];self.c.commit();plan=plan_scope(self.c,self.root/'plan.sqlite')
        self.c.execute("UPDATE jobs SET status='dispatched' WHERE id=?",(ids[-1],));self.c.commit();apply_plan(self.root,plan)
        self.assertEqual('dispatched',self.c.execute('SELECT status FROM jobs WHERE id=?',(ids[-1],)).fetchone()[0]);plan.close()
    def test_new_work_jobs_follow_active_distinct_name_policy(self):
        self.c.execute('INSERT INTO preparation VALUES(?,?)',('focused-current-year-v1','{}'));self.c.execute('INSERT INTO preparation VALUES(?,?)',(USEFUL_POLICY,'{}'))
        ids=[self.add('1',n,label) for n,label in enumerate(['Road','Road!','Water','Drain','School'],1)]
        self.assertEqual(['queued','deferred','queued','queued','deferred'],[self.c.execute('SELECT status FROM jobs WHERE id=?',(k,)).fetchone()[0] for k in ids])
    def test_payment_job_cannot_be_deferred_by_work_scope_plan(self):
        key=add_job(self.c,'9','1','voucher','https://egramswaraj.gov.in/voucherWiseReport.do?month=10',{'month':'2026-10'});self.c.commit();plan=plan_scope(self.c,self.root/'plan.sqlite')
        plan.execute('INSERT INTO defer_jobs(id) VALUES(?)',(key,));plan.commit();apply_plan(self.root,plan)
        self.assertEqual('queued',self.c.execute('SELECT status FROM jobs WHERE id=?',(key,)).fetchone()[0]);plan.close()
    def test_interrupted_chunked_rollout_resumes_without_reapplying_committed_changes(self):
        from contextlib import contextmanager
        for number in range(1,9):self.add('1',number,'Work '+str(number))
        self.c.commit();plan=plan_scope(self.c,self.root/'plan.sqlite');calls=0
        @contextmanager
        def interrupt(work):
            nonlocal calls
            calls+=1
            if calls==2:raise RuntimeError('Simulated interruption')
            yield
        with self.assertRaisesRegex(RuntimeError,'Simulated'):
            apply_plan(self.root,plan,chunk=1,guard=interrupt)
        self.assertEqual(1,self.c.execute("SELECT count(*) FROM jobs WHERE status='deferred'").fetchone()[0]);self.assertIsNone(self.c.execute('SELECT value FROM preparation WHERE name=?',(USEFUL_POLICY,)).fetchone())
        apply_plan(self.root,plan,chunk=2)
        self.assertEqual(5,self.c.execute("SELECT count(*) FROM jobs WHERE status='deferred'").fetchone()[0]);self.assertEqual(3,self.c.execute("SELECT count(*) FROM jobs WHERE status='queued'").fetchone()[0]);self.assertEqual(8,self.c.execute('SELECT sum(total) FROM job_counts').fetchone()[0]);plan.close()
    def test_normalization_preserves_language_and_location_numbers(self):
        self.assertEqual(work_name('ROAD repair'),work_name('Road-repair!'));self.assertNotEqual(work_name('Ward 1 road'),work_name('Ward 2 road'));self.assertIsNotNone(work_name('पेयजल कार्य'));self.assertIsNone(work_name('12345'));self.assertNotEqual(work_name('काम'),work_name('कम'))
if __name__=='__main__':unittest.main()
