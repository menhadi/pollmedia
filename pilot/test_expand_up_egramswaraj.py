import copy
import json
import tempfile
import unittest
from pathlib import Path
import test_expand_pilibhit_panchayat_reports as samples
from expand_pilibhit_panchayat_reports import monthly,digest
from expand_up_egramswaraj import capture_index,source_capture,verified_roster,district_build

class UpEgramTest(unittest.TestCase):
    def test_financial_report_uses_egsam_district_code_and_explicit_official_aliases(self):
        text=samples.PilibhitExpansionTest().monthly_html("Old GP").replace("District:-Pilibhit","District:-Bareilly")
        url="https://egramswaraj.gov.in/voucherWiseReport.do?state=9&amp;district=121&amp;block=1541&amp;village=69232&amp;finYear=2026-2027&amp;month=4"
        text=text.replace("April, 2026",'<a href="'+url+'">April, 2026</a>')
        plan={"gp_cd":69232,"gp_nam":"New GP","gp_aliases":["Old GP"],"parent_cd":1541,"zp_cd":121,"zp_name":"Bareilly"}
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/"monthly.html";p.write_text(text)
            months,date,requests=monthly(p,plan)
            self.assertEqual(len(months),8);self.assertEqual(len(requests),1)
            p.write_text(text.replace("district=121","district=130"))
            with self.assertRaises(ValueError):monthly(p,plan)
            p.write_text(text.replace("District:-Bareilly","District:-Pilibhit"))
            with self.assertRaises(ValueError):monthly(p,plan)

    def test_capture_index_binds_url_and_handles_dotnet_timestamps_across_midnight(self):
        with tempfile.TemporaryDirectory() as d:
            r=Path(d);(r/"a.json").write_text("{}")
            row={"file":"a.json","url":"https://egramswaraj.gov.in/activitySectionData.do?activityCd=1","captured_at":"2026-11-14T18:31:00.1234567+00:00"}
            (r/"_capture-test.jsonl").write_text(json.dumps(row)+"\n")
            index=capture_index(r)
            self.assertEqual(source_capture(r,index,"a.json",row["url"])[1],"2026-11-15")
            with self.assertRaises(ValueError):source_capture(r,index,"a.json",row["url"]+"0")

    def fixtures(self,root):
        captures=root/"captures";captures.mkdir();fixtures=root/"fixtures";fixtures.mkdir()
        village={"code":"133001","name":"Village A","district_code":"130","district_name":"Bareilly","panchayats":[{"code":"69232","name":"Alpha"}],"blocks":[{"code":"1001","name":"Civil Block"}]}
        civil=fixtures/"up-panchayat-directory-9-130.json";civil.write_text(json.dumps({"villages":[village]}))
        (fixtures/"up-panchayat-directories.json").write_text(json.dumps({"schema":1,"publication_enabled":True,"districts":{"130":{"file":civil.name,"sha256":digest(civil)}}}))
        (captures/"districts.json").write_text(json.dumps([{"lgd":"130","name":"Bareilly"}]))
        block={"zp_cd":121,"zp_name":"Bareilly","parent_cd":1541,"parent_name":"Official Block"}
        (captures/"official-up-zp-plans.json").write_text(json.dumps([block]))
        plan=block|{"gp_cd":69232,"gp_nam":"Alpha","plan_cd":6000001,"plantype":"Main Plan"}
        (captures/"official-plans-1541.json").write_text(json.dumps([plan]))
        rows=[]
        for gp,name in [("69232","Alpha"),("69233","Unmapped")]:
            row=[""]*18;row[1]="BAREILLY";row[2]="OFFICIAL BLOCK";row[3]=name;row[16]="1541";row[17]=gp
            rows.append("<tr>"+"".join("<td>"+value+"</td>" for value in row)+"</tr>")
        (captures/"official-up-BAREILLY.html").write_text("<table>"+"".join(rows)+"</table>")
        return captures,fixtures

    def test_numeric_namespace_correspondence_is_verified_and_unmapped_gp_is_held(self):
        with tempfile.TemporaryDirectory() as d:
            captures,fixtures=self.fixtures(Path(d));rows=verified_roster(captures,fixtures)
            self.assertEqual(len(rows),1);self.assertEqual(rows[0]["district_lgd"],"130")
            self.assertEqual(rows[0]["zp_cd"],121);self.assertEqual(rows[0]["block_lgd"],"1001")
            self.assertEqual(rows[0]["block_egs"],1541);self.assertEqual(rows[0]["villages"],["133001"])
            self.assertEqual(len(json.loads((captures/"identity-held.json").read_text())),1)
            civil=fixtures/"up-panchayat-directory-9-130.json";civil.write_text("{}")
            with self.assertRaises(ValueError):verified_roster(captures,fixtures)

    def test_conflicting_official_parent_block_is_not_guessed(self):
        with tempfile.TemporaryDirectory() as d:
            captures,fixtures=self.fixtures(Path(d))
            p=captures/"official-plans-1541.json";rows=json.loads(p.read_text());rows[0]["parent_cd"]=1542;p.write_text(json.dumps(rows))
            self.assertEqual(verified_roster(captures,fixtures),[])

    def test_unavailable_activity_list_does_not_erase_verified_monthly_data(self):
        with tempfile.TemporaryDirectory() as d:
            root=Path(d);captures,fixtures=self.fixtures(root);roster=verified_roster(captures,fixtures)
            text=samples.PilibhitExpansionTest().monthly_html("Alpha").replace("District:-Pilibhit","District:-Bareilly")
            (captures/"official-monthly-69232.html").write_text(text)
            (captures/"official-activities-69232-6000001.json").write_text("null")
            index={"official-monthly-69232.html":{"day":"2026-11-14","url":"https://egramswaraj.gov.in/FileRedirect.jsp?FD=FinancialYear2026-2027/9&name=69232.html"},
                   "official-activities-69232-6000001.json":{"day":"2026-11-14","url":"https://egramswaraj.gov.in/getActivityList.do?planCd=6000001&activityStts=0"}}
            out=root/"out";summary,_=district_build(captures,out,"130",roster,index,{},True)
            self.assertEqual(summary["published_panchayats"],1);self.assertEqual(summary["held_components"],1)
            report=json.loads((out/"9-130-69232-2026-2027.json").read_text())["report"]
            self.assertEqual(len(report["months"]),8);self.assertEqual(report.get("activities",[]),[])

if __name__=="__main__":unittest.main()
