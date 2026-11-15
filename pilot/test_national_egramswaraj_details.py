import json
import tempfile
import unittest
import zipfile
from pathlib import Path
from national_egramswaraj_details import add_job, consume, finish, ingest, packed, queue, record, sha, store, unpacked, vouchers

class NationalDetailsTest(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name);self.work=self.root/'work';self.work.mkdir();self.c=queue(self.work);self.db=store(self.root/'records','3');self.db.commit()
    def tearDown(self):
        self.db.close();self.c.close();self.temp.cleanup()
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
        result=self.work/'results.json';result.write_text(json.dumps([{'file':'detail-'+child+'.json','ok':False}]))
        finish(self.work,self.root/'records',result)
        row=self.db.execute("SELECT * FROM records WHERE kind='activities'").fetchone();value=unpacked(row['payload'])
        self.assertEqual('listed',row['level']);self.assertEqual('Road',value['name']);self.assertEqual('Details unavailable',value['status']);self.assertTrue(value['notes']);self.assertEqual('note',self.c.execute('SELECT status FROM jobs WHERE id=?',(child,)).fetchone()[0])
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
