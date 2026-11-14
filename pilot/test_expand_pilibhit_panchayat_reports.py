import copy
import tempfile
import unittest
from pathlib import Path
from expand_pilibhit_panchayat_reports import activity, money, monthly, review_correspondence
import json

class PilibhitExpansionTest(unittest.TestCase):
    def row(self, gp="81720", village="131318"):
        return ["1","31","UTTAR PRADESH","PILIBHIT","3126","AMARIYA","3126001","AMARIYA","3126001004","3126001004001","AMARIYA","173","PILIBHIT","AMARIYA","1439",gp,"AMARIA",village,"Amaria"]

    def review(self, rows):
        gps={"81720":{"names":{"Amaria"},"blocks":{"Amariya"},"villages":{"131318"}},
             "81721":{"names":{"Amkhera"},"blocks":{"Amariya"},"villages":{"131319"}},
             "82094":{"names":{"Amkhera"},"blocks":{"Marori"},"villages":{"131376"}}}
        source={"source_url":"https://vbgramgrep.dord.gov.in/VBGRAMG/LGD_Mapping_Staus1.aspx","original_sha256":"a"*64,"rows":rows}
        return review_correspondence(source,{"panchayats":[{"code":"3126001004","name":"AMARIYA"}]},gps,{"131318":{},"131319":{},"131376":{}},{})["records"][0]

    def test_alias_uses_explicit_code_instead_of_guessing_english_spelling(self):
        record=self.review([self.row()])
        self.assertEqual(record["status"],"verified")
        self.assertEqual(record["gp"],"81720")
        self.assertEqual(record["name"],"Amaria")
        self.assertEqual(record["matched_villages"],["131318"])

    def test_split_village_boundaries_and_wrong_current_block_are_held(self):
        record=self.review([self.row(village="131319")])
        self.assertEqual(record["status"],"held")
        self.assertEqual(record["conflicting_villages"],["131319"])
        record=self.review([self.row(gp="82094",village="131376")])
        self.assertEqual(record["status"],"held")
        self.assertIn("different current block",record["reason"])

    def test_missing_or_competing_gp_codes_are_not_filled_by_names(self):
        self.assertEqual(self.review([])["status"],"held")
        self.assertEqual(self.review([self.row(),self.row(gp="81721")])["status"],"held")

    def test_invalid_official_village_reference_never_becomes_a_village_link(self):
        record=self.review([self.row(village="81720")])
        self.assertEqual(record["status"],"verified")
        self.assertEqual(record["matched_villages"],[])
        self.assertEqual(record["invalid_village_references"],["81720"])
        self.assertIn("accepted current LGD directory only",record["village_evidence"])

    def monthly_html(self, name="Amaria"):
        month_names=["April","May","June","July","August","September","October","November","December"]
        rows="".join("<tr><td>"+m+", 2026</td><td>0</td><td>0</td><td>0</td><td>0</td></tr>" for m in month_names)
        return "<p>Month wise Summary Report Financial Year:-2026-2027 State:-Uttar Pradesh,District:-Pilibhit,Village Panchayat &amp; Equivalent:-"+name+"</p><table><tr><th>Month</th><th>Receipts</th><th>Payments</th><th>Contra</th><th>Journal</th></tr>"+rows+"</table><p>Report Completed Report generated on Friday, November 6, 2026</p>"

    def test_monthly_future_zero_placeholders_are_excluded_and_source_date_is_preserved(self):
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/"monthly.html";p.write_text(self.monthly_html())
            rows,date,_=monthly(p,{"gp_cd":81720,"gp_nam":"Amaria"})
            self.assertEqual(len(rows),8)
            self.assertEqual(rows[-1]["month"],"2026-11")
            self.assertEqual(date,"2026-11-06")
            p.write_text(self.monthly_html("Another Panchayat"))
            with self.assertRaises(ValueError):
                monthly(p,{"gp_cd":81720,"gp_nam":"Amaria"})

    def test_activity_requires_membership_and_matching_panchayat_and_year(self):
        raw={"activityCd":123,"plnUntCd":81720,"fnclYr":2026,"activityName":"Drainage work","focusArea":"Sanitation","activityType":"Community Works","activityStts":"Activity Approved","outputTyp":"Asset","totalCost":None}
        plan={"gp_cd":81720,"plan_cd":999,"plantype":"Main Plan"}
        with tempfile.TemporaryDirectory() as d:
            p=Path(d)/"activity.json";p.write_text(json.dumps(raw))
            result=activity(p,"Drainage work (123)",plan)
            self.assertIsNone(result["cost"])
            self.assertEqual(result["status"],"Activity Approved")
            for key,value in [("plnUntCd",81721),("fnclYr",2025),("activityCd",124),("activityName","Different work")]:
                changed=copy.deepcopy(raw);changed[key]=value;p.write_text(json.dumps(changed))
                with self.assertRaises(ValueError):
                    activity(p,"Drainage work (123)",plan)

    def test_money_conversion_keeps_paise_and_rejects_invalid_numbers(self):
        self.assertEqual(money("1,234.56"),123456)
        self.assertEqual(money("1,46,700"),14670000)
        for value in ["-5","unavailable","1.234","1,,234"]:
            with self.assertRaises(ValueError):
                money(value)

if __name__=="__main__":
    unittest.main()
