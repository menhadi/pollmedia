import json
import tempfile
import unittest
import io
import time
from contextlib import redirect_stdout
import zipfile
from pathlib import Path
from national_egramswaraj_details import add_job, consume, finish, ingest, optimize, next_batch, resume_batch, requeue_batch, pending_count, packed, queue, record, sha, store, unpacked, vouchers

class NationalDetailsTest(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name);self.work=self.root/'work';self.work.mkdir();self.c=queue(self.work);self.db=store(self.root/'records','3');self.db.commit()
    def tearDown(self):
        self.db.close();self.c.close();self.temp.cleanup()
    def test_blank_context_payment_report_is_retried_without_zero_records(self):
        raw=b'<h1>Voucher Wise Summary Report</h1><p>Financial Year : Month : Scheme :</p><p>Voucher Amount (in Rs.)</p><table><tr><th>Receipts</th><th>Payments</th></tr><tr><td>No Data Found!!</td></tr></table>'
        url='https://egramswaraj.gov.in/voucherWiseReport.do?state=3&village=10074'
        key=add_job(self.c,'3','10074','voucher',url,dict(self.plan(),month='2026-04'));self.c.commit()
        archive=self.work/'blank.zip'
        with zipfile.ZipFile(archive,'w') as z:
            name='detail-'+key+'.html';z.writestr(name,raw)
            z.writestr('_capture-test.jsonl',json.dumps({'file':name,'url':url,'captured_at':'2026-11-15T10:00:00+00:00'}))
        ingest(self.work,self.root/'records',archive)
        row=self.c.execute('SELECT * FROM jobs WHERE id=?',(key,)).fetchone()
        self.assertEqual('queued',row['status']);self.assertEqual(1,row['fetch_attempts']);self.assertGreater(row['retry_after'],time.time()+21000)
        self.assertEqual(0,self.db.execute('SELECT count(*) FROM records').fetchone()[0])
    def plan(self):
        return {'state':'3','gp':'10074','plan':'1234','gp_cd':10074,'plan_cd':1234,'plantype':'Main Plan'}
    def test_plan_membership_then_detail_preserves_source_evidence_and_exact_cost(self):
        context=self.plan();url='https://egramswaraj.gov.in/getActivityList.do?planCd=1234&activityStts=0'
        key=add_job(self.c,'3','10074','plan',url,context);job=self.c.execute('SELECT * FROM jobs WHERE id=?',(key,)).fetchone()
        meta={'captured_at':'2026-11-14T21:00:00.1234567+00:00'}
        source,_=consume(self.c,self.db,job,json.dumps(['Repair road (88) ']).encode(),meta)
        self.assertEqual('2026-11-15',source['day'])
        self.assertEqual('listed',self.db.execute('SELECT level FROM records').fetchone()[0])
        child=self.c.execute("SELECT * FROM jobs WHERE kind='activity'").fetchone()
        raw=json.dumps({'activityCd':88,'plnUntCd':10074,'fnclYr':2026,'activityName':'Road repairs','totalCost':12.34,'activityStts':'Approved'}).encode()
        consume(self.c,self.db,child,raw,meta)
        row=self.db.execute('SELECT * FROM records').fetchone();value=unpacked(row['payload'])
        self.assertEqual('complete',row['level']);self.assertEqual('12.34',value['reported_cost']);self.assertIsNone(value['cost_unit']);self.assertTrue(value['notes']);self.assertEqual(source['sha'],value['plan_membership_sha256'])
        counts=dict(self.db.execute("SELECT key,value FROM metadata WHERE key LIKE 'count:%'"));self.assertEqual('0',counts['count:activities_listed']);self.assertEqual('1',counts['count:activities_complete'])
        consume(self.c,self.db,job,json.dumps(['Repair road (88) ']).encode(),meta)
        self.assertEqual('complete',self.db.execute('SELECT level FROM records').fetchone()[0])
    def test_activity_from_another_panchayat_is_not_assigned(self):
        context=dict(self.plan(),activity='88',label='Road',plan_membership_url='https://egramswaraj.gov.in/getActivityList.do?planCd=1234&activityStts=0',plan_membership_sha256='a'*64)
        key=add_job(self.c,'3','10074','activity','https://egramswaraj.gov.in/activitySectionData.do?activityCd=88',context)
        job=self.c.execute('SELECT * FROM jobs WHERE id=?',(key,)).fetchone()
        with self.assertRaisesRegex(ValueError,'identifier/Panchayat/year'):
            consume(self.c,self.db,job,b'{"activityCd":88,"plnUntCd":9999,"fnclYr":2026}',{'captured_at':'2026-11-15T01:00:00Z'})
        self.assertEqual(0,self.db.execute('SELECT count(*) FROM records').fetchone()[0])
    def test_activity_from_another_state_is_not_assigned(self):
        context=dict(self.plan(),state_name='Punjab',activity='88',label='Road',plan_membership_url='https://egramswaraj.gov.in/getActivityList.do?planCd=1234&activityStts=0',plan_membership_sha256='a'*64)
        key=add_job(self.c,'3','10074','activity','https://egramswaraj.gov.in/activitySectionData.do?activityCd=88',context)
        job=self.c.execute('SELECT * FROM jobs WHERE id=?',(key,)).fetchone()
        with self.assertRaisesRegex(ValueError,'source state differs'):
            consume(self.c,self.db,job,b'{"activityCd":88,"plnUntCd":10074,"fnclYr":2026,"stateName":"Goa","activityName":"Road"}',{'captured_at':'2026-11-15T01:00:00Z'})
    def voucher_html(self,state='3',with_link=True):
        cells=['']*15;cells[4]='15/11/2026';cells[5]='PAY/1';cells[6]='Payment';cells[7]='1,234.56'
        if with_link:cells[5]='<a href="https://egramswaraj.gov.in/paymentVoucherDetail.do?PRIEntity_code=10074&amp;stateCode='+state+'&amp;finYear=2026-2027&amp;voucherID=77">PAY/1</a>'
        return ('Voucher Wise Summary Report Voucher Amount (in Rs.) on Sunday, 01 Nov 2026 <table><tr><th>Receipts</th><th>Payments</th></tr><tr>'+''.join('<td>'+v+'</td>' for v in cells)+'</tr></table>').encode()
    def test_voucher_keeps_rupee_precision_and_report_date_note(self):
        rows=vouchers(self.voucher_html(),{'state':'3','gp':'10074','month':'2026-11'})
        self.assertEqual(1,len(rows));kind,key,row=rows[0];self.assertEqual(('payments','77'),(kind,key));self.assertEqual(123456,row['amount_paise']);self.assertEqual('2026-11-15',row['date']);self.assertTrue(row['notes'])
        future=vouchers(self.voucher_html(),{'state':'3','gp':'10074','month':'2026-11','captured_on':'2026-11-10'})
        self.assertTrue(any('later than retrieval' in n for n in future[0][2]['notes']))
    def test_foreign_voucher_reference_is_rejected_but_missing_reference_is_not_lost(self):
        with self.assertRaisesRegex(ValueError,'another Panchayat/state/year'):vouchers(self.voucher_html(state='9'),{'state':'3','gp':'10074','month':'2026-11'})
        rows=vouchers(self.voucher_html(with_link=False),{'state':'3','gp':'10074','month':'2026-11'});self.assertEqual(1,len(rows));self.assertIsNone(rows[0][2]['detail_url']);self.assertTrue(rows[0][2]['notes'])
    def test_retry_does_not_duplicate_records_and_prior_versions_are_retained(self):
        source={'url':'https://egramswaraj.gov.in/voucherWiseReport.do?state=3&village=10074&finYear=2026-2027','sha':'a'*64,'day':'2026-11-15'}
        row={'voucher':'PAY/1','amount_paise':100,'date':'2026-11-15'}
        record(self.db,'10074','payments','77',row,source);record(self.db,'10074','payments','77',row,source)
        self.assertEqual(1,self.db.execute('SELECT count(*) FROM records').fetchone()[0]);self.assertEqual('1',self.db.execute("SELECT value FROM metadata WHERE key='count:payments'").fetchone()[0])
        record(self.db,'10074','payments','77',dict(row,amount_paise=200),dict(source,sha='b'*64))
        self.assertEqual(100,unpacked(self.db.execute('SELECT payload FROM versions').fetchone()[0])['amount_paise'])
    def test_failed_individual_fetch_keeps_listing_but_does_not_claim_it_is_still_queued(self):
        url='https://egramswaraj.gov.in/getActivityList.do?planCd=1234&activityStts=0';key=add_job(self.c,'3','10074','plan',url,self.plan())
        job=self.c.execute('SELECT * FROM jobs WHERE id=?',(key,)).fetchone();consume(self.c,self.db,job,b'["Road (88)"]',{'captured_at':'2026-11-15T00:00:00Z'})
        child=self.c.execute("SELECT id FROM jobs WHERE kind='activity'").fetchone()[0];self.c.commit();self.db.commit()
        result=self.work/'results.json';result.write_text(json.dumps([{'file':'detail-'+child+'.json','ok':False,'status':404}]))
        finish(self.work,self.root/'records',result)
        row=self.db.execute("SELECT * FROM records WHERE kind='activities'").fetchone();value=unpacked(row['payload'])
        self.assertEqual('listed',row['level']);self.assertEqual('Road',value['name']);self.assertEqual('Details unavailable',value['status']);self.assertTrue(value['notes']);self.assertEqual('note',self.c.execute('SELECT status FROM jobs WHERE id=?',(child,)).fetchone()[0])
    def test_dispatch_uses_indexes_and_counters_without_duplicate_leases(self):
        self.c.commit();optimize(self.work)
        for i in range(60):
            add_job(self.c,'3','10074','activity','https://egramswaraj.gov.in/activitySectionData.do?activityCd='+str(100+i),dict(self.plan(),activity=str(100+i)))
        self.c.commit();self.assertEqual(60,pending_count(self.c))
        add_job(self.c,'3','10074','activity','https://egramswaraj.gov.in/activitySectionData.do?activityCd=100',self.plan());self.c.commit();self.assertEqual(60,pending_count(self.c))
        plans=list(self.c.execute("EXPLAIN QUERY PLAN SELECT * FROM jobs WHERE status='queued' AND kind=? AND retry_after<=? ORDER BY gp,state,id LIMIT ?",('activity',time.time(),20)))
        self.assertFalse(any('TEMP B-TREE' in r[3] for r in plans))
        out=io.StringIO()
        with redirect_stdout(out):next_batch(self.work,20)
        batch=json.loads(out.getvalue());self.assertEqual(20,batch['requests'])
        rows=json.loads(Path(batch['manifest']).read_text());self.assertEqual(20,len({r['file'] for r in rows}));self.assertEqual(20,self.c.execute("SELECT count(*) FROM jobs WHERE status='dispatched'").fetchone()[0]);self.assertEqual(60,pending_count(self.c))
        actual={(r[0],r[1],r[2]):r[3] for r in self.c.execute('SELECT state,kind,status,count(*) FROM jobs GROUP BY state,kind,status')}
        counters={(r[0],r[1],r[2]):r[3] for r in self.c.execute('SELECT * FROM job_counts WHERE total>0')};self.assertEqual(actual,counters)
    def test_transient_timeouts_are_delayed_not_published_as_missing_or_retried_immediately(self):
        optimize(self.work)
        key=add_job(self.c,'3','10074','activity','https://egramswaraj.gov.in/activitySectionData.do?activityCd=88',dict(self.plan(),activity='88'));self.c.commit()
        result=self.work/'timeouts.json';result.write_text(json.dumps([{'file':'detail-'+key+'.json','ok':False,'status':None}]))
        finish(self.work,self.root/'records',result)
        row=self.c.execute('SELECT status,retry_after,fetch_attempts FROM jobs WHERE id=?',(key,)).fetchone();self.assertEqual('queued',row['status']);self.assertEqual(1,row['fetch_attempts']);self.assertGreater(row['retry_after'],time.time()+590)
        out=io.StringIO()
        with redirect_stdout(out):next_batch(self.work,20)
        self.assertEqual(0,json.loads(out.getvalue())['requests']);self.assertEqual(1,json.loads(out.getvalue())['pending'])
        self.assertEqual(0,self.db.execute('SELECT count(*) FROM requests').fetchone()[0])
    def test_resume_never_downloads_already_committed_records_again(self):
        optimize(self.work)
        ids=[add_job(self.c,'3','10074','activity','https://egramswaraj.gov.in/activitySectionData.do?activityCd='+str(i),dict(self.plan(),activity=str(i))) for i in [88,89]]
        batch='batch-20261115T010203000000'
        self.c.execute("UPDATE jobs SET status='dispatched',batch=?",(batch,));self.c.execute("UPDATE jobs SET status='done' WHERE id=?",(ids[0],));self.c.commit()
        out=io.StringIO()
        with redirect_stdout(out):resume_batch(self.work,batch)
        result=json.loads(out.getvalue());rows=json.loads(Path(result['manifest']).read_text());self.assertEqual(1,result['requests']);self.assertEqual('detail-'+ids[1]+'.json',rows[0]['file']);self.assertEqual('done',self.c.execute('SELECT status FROM jobs WHERE id=?',(ids[0],)).fetchone()[0])
    def test_financial_and_activity_lanes_lease_disjoint_requests(self):
        optimize(self.work)
        for kind,url in [('voucher','voucherWiseReport.do?state=3&village=10074&finYear=2026-2027'),('plan','getActivityList.do?planCd=1234&activityStts=0'),('activity','activitySectionData.do?activityCd=88')]:
            add_job(self.c,'3','10074',kind,'https://egramswaraj.gov.in/'+url,self.plan())
        self.c.commit();out=io.StringIO()
        with redirect_stdout(out):next_batch(self.work,20,'financial')
        financial=json.loads(out.getvalue());self.assertEqual(1,financial['requests'])
        out=io.StringIO()
        with redirect_stdout(out):next_batch(self.work,20,'activities')
        activities=json.loads(out.getvalue());self.assertEqual(2,activities['requests'])
        left={x['file'] for x in json.loads(Path(financial['manifest']).read_text())};right={x['file'] for x in json.loads(Path(activities['manifest']).read_text())};self.assertFalse(left&right)
        self.assertEqual(1,pending_count(self.c,'financial'));self.assertEqual(2,pending_count(self.c,'activities'))
        requeue_batch(self.work,financial['batch']);self.assertEqual(1,self.c.execute("SELECT count(*) FROM jobs WHERE status='queued'").fetchone()[0]);self.assertEqual(2,self.c.execute("SELECT count(*) FROM jobs WHERE status='dispatched'").fetchone()[0])
    def test_dispatch_retry_reuses_the_same_batch_instead_of_leasing_more_records(self):
        optimize(self.work)
        for i in range(50):add_job(self.c,'3','10074','activity','https://egramswaraj.gov.in/activitySectionData.do?activityCd='+str(100+i),self.plan())
        self.c.commit();batch='batch-20261115T010203000001';one=io.StringIO();two=io.StringIO()
        with redirect_stdout(one):next_batch(self.work,20,'activities',batch)
        with redirect_stdout(two):next_batch(self.work,20,'activities',batch)
        a=json.loads(one.getvalue());b=json.loads(two.getvalue());self.assertEqual(json.loads(Path(a['manifest']).read_text()),json.loads(Path(b['manifest']).read_text()));self.assertEqual(20,self.c.execute("SELECT count(*) FROM jobs WHERE status='dispatched'").fetchone()[0])
    def test_recovered_voucher_is_bound_to_its_source_and_retains_day_precision(self):
        from recover_egramswaraj_capture import recover
        from datetime import datetime,timezone
        import os
        url='https://egramswaraj.gov.in/voucherWiseReport.do?state=3&village=10074&finYear=2026-2027&month=11';key=add_job(self.c,'3','10074','voucher',url,{'state':'3','gp':'10074','month':'2026-11'});self.c.commit();self.db.commit()
        part=self.work/'capture-financial-20261115T010203000000-0001.part';filename='detail-'+key+'.html';body=self.voucher_html()
        with zipfile.ZipFile(part,'w',compression=zipfile.ZIP_DEFLATED) as z:z.writestr(filename,body)
        with zipfile.ZipFile(part) as z:end=z.start_dir
        part.write_bytes(part.read_bytes()[:end]);stamp=datetime(2026,11,15,2,tzinfo=timezone.utc).timestamp();os.utime(part,(stamp,stamp));before=part.read_bytes()
        manifest=self.work/'recover-manifest.json';manifest.write_text(json.dumps([{'file':filename,'url':url}]));archive=self.work/'recovered.zip';recover(part,manifest,archive);ingest(self.work,self.root/'records',archive)
        row=self.db.execute("SELECT payload,source_sha256,captured_on FROM records WHERE kind='payments'").fetchone();value=unpacked(row['payload']);self.assertEqual(123456,value['amount_paise']);self.assertEqual('day',value['capture_precision']);self.assertEqual('2026-11-15',row['captured_on']);self.assertEqual(sha(body),row['source_sha256']);self.assertEqual(before,part.read_bytes())
    def test_archive_url_binding_and_idempotent_ingestion(self):
        url='https://egramswaraj.gov.in/getActivityList.do?planCd=1234&activityStts=0';key=add_job(self.c,'3','10074','plan',url,self.plan());self.c.commit()
        archive=self.work/'test.zip';filename='detail-'+key+'.json'
        with zipfile.ZipFile(archive,'w') as z:
            z.writestr(filename,'[]');z.writestr('_capture-test.jsonl',json.dumps({'file':filename,'url':url,'captured_at':'2026-11-15T00:00:00.1234567Z'})+'\n')
        ingest(self.work,self.root/'records',archive);ingest(self.work,self.root/'records',archive)
        self.assertEqual('done',self.c.execute('SELECT status FROM jobs').fetchone()[0])
        wrong=self.work/'wrong.zip'
        with zipfile.ZipFile(wrong,'w') as z:
            z.writestr(filename,'[]');z.writestr('_capture-wrong.jsonl',json.dumps({'file':filename,'url':url+'&state=9','captured_at':'2026-11-15T00:00:00Z'})+'\n')
        with self.assertRaisesRegex(ValueError,'bound'):ingest(self.work,self.root/'records',wrong)

if __name__=='__main__':unittest.main()
