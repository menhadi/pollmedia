import json,tempfile,unittest
from pathlib import Path
from national_egramswaraj_summaries import parse,prepare,catalog,publish
from test_expand_pilibhit_panchayat_reports import PilibhitExpansionTest

class NationalSummaryTest(unittest.TestCase):
    def source(self,name="Amaria"):
        return PilibhitExpansionTest().monthly_html(name).encode()
    def test_monthly_counts_keep_source_dates_and_do_not_fetch_details(self):
        row=parse(self.source(),"9","81720","2026-11-15","Amaria","Uttar Pradesh",{"173":"Pilibhit"})
        self.assertTrue(row["identity_match"]);self.assertEqual(row["report_date"],"2026-11-06");self.assertEqual(len(row["monthly"]),8)
        self.assertEqual(row["status"],"available");self.assertNotIn("activities",row);self.assertNotIn("vouchers",row)
    def test_block_header_is_not_mistaken_for_panchayat_name(self):
        body=self.source().replace(b",Village Panchayat",b",Block Panchayat &amp; Equivalent:-A Block,Village Panchayat")
        row=parse(body,"9","81720","2026-11-15","Amaria","Uttar Pradesh",{"173":"Pilibhit"})
        self.assertEqual(row["name"],"Amaria");self.assertTrue(row["identity_match"])

    def test_name_difference_is_published_with_note_without_village_confirmation(self):
        row=parse(self.source("Official new name"),"9","81720","2026-11-15","Amaria","Uttar Pradesh",{"173":"Pilibhit"})
        self.assertEqual(row["status"],"available");self.assertEqual(row["name"],"Official new name");self.assertFalse(row["identity_match"]);self.assertTrue(row["notes"])
    def test_missing_header_name_does_not_implicitly_confirm_directory_name(self):
        body=self.source().replace(b"Village Panchayat &amp; Equivalent:-Amaria",b"Source identifier:-81720")
        row=parse(body,"9","81720","2026-11-15","Amaria","Uttar Pradesh",{"173":"Pilibhit"})
        self.assertFalse(row["identity_match"]);self.assertTrue(row["notes"])
    def test_future_nonzero_month_is_visible_with_note(self):
        body=self.source().replace(b"December, 2026</td><td>0",b"December, 2026</td><td>7")
        row=parse(body,"9","81720","2026-11-15","Amaria","Uttar Pradesh",{"173":"Pilibhit"})
        self.assertEqual(row["monthly"][-1]["receipts"],7);self.assertEqual(row["monthly"][-1]["month"],"2026-12");self.assertIn("note",row["monthly"][-1])
    def test_missing_file_does_not_become_zero_accounts(self):
        row=parse(b'<script>alert("File does not exist");</script>',"9","81720","2026-11-15","Amaria","Uttar Pradesh",{})
        self.assertEqual(row["status"],"unavailable");self.assertEqual(row["monthly"],[]);self.assertIsNone(row["report_date"])
    def test_requested_year_is_not_substituted_for_different_source_year(self):
        row=parse(self.source().replace(b"2026-2027",b"2025-2026"),"9","81720","2026-11-15","Amaria","Uttar Pradesh",{})
        self.assertEqual(row["financial_year"],"2025-2026");self.assertEqual(row["status"],"format_note");self.assertTrue(row["monthly"])

if __name__=="__main__":unittest.main()
