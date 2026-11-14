import copy
import tempfile
import unittest
from pathlib import Path
from expand_pilibhit_other_blocks import verify_plan
from expand_pilibhit_panchayat_reports import monthly
import test_expand_pilibhit_panchayat_reports as fixtures

class OtherBlocksTest(unittest.TestCase):
    def setUp(self):
        self.plan={"gp_cd":81810,"gp_nam":"Aamdar","parent_name":"Barkhera","parent_cd":2022,"zp_cd":164,"plantype":"Main Plan"}
        self.gps={"81810":{"names":{"Aamdar"},"blocks":{"Barkhera"},"villages":{"131600"}}}
        self.state=[["PILIBHIT","BARKHERA","AAMDAR","2022","81810"]]

    def test_independent_numeric_identity_allows_official_name_variants_but_not_wrong_block(self):
        state=copy.deepcopy(self.state);state[0][2]="Aamdar village"
        self.assertEqual(verify_plan(self.plan,self.gps,state),self.gps["81810"])
        for field,value in [(0,"BAREILLY"),(1,"MARORI"),(3,"2026"),(4,"81811")]:
            state=copy.deepcopy(self.state);state[0][field]=value
            with self.assertRaises(ValueError):verify_plan(self.plan,self.gps,state)
        with self.assertRaises(ValueError):verify_plan(self.plan,self.gps,self.state+self.state)

    def test_monthly_voucher_links_must_match_actual_other_block_not_amariya(self):
        template=fixtures.PilibhitExpansionTest().monthly_html("Aamdar")
        link='https://egramswaraj.gov.in/voucherWiseReport.do?state=9&amp;district=164&amp;block=2022&amp;village=81810&amp;finYear=2026-2027&amp;month=4'
        text=template.replace('April, 2026','<a href="'+link+'">April, 2026</a>')
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/"monthly.html";p.write_text(text)
            rows,date,requests=monthly(p,self.plan)
            self.assertEqual(len(rows),8);self.assertEqual(requests[0]["gp"],"81810")
            p.write_text(text.replace("block=2022","block=2021"))
            with self.assertRaises(ValueError):monthly(p,self.plan)

if __name__=="__main__":unittest.main()
