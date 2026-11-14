import unittest
from build_pilibhit_rural_employment import decimal_value, normalized, official, parse_work

class RuralEmploymentCaptureTest(unittest.TestCase):
    def capture(self):
        cells = lambda *values: [{"text": value} for value in values]
        return {"text":"Expenditure on Muster Roll 2026-2027 PILIBHIT AMARIYA Amount on UnSkilled(In Rupees) Grand Total Rs. 1200 Report Completed",
                "tables":[[cells("Work Name: Public drainage work (3126001124/IC/123)"), cells("21", "01/11/2026", "1200")]]}

    def test_work_identity_and_amount_reconcile_before_publication(self):
        works, total = parse_work(self.capture(), "3126001124")
        self.assertEqual(total, 120000)
        self.assertEqual(works[0]["musters"][0]["reported_payment_date"], "2026-11-01")
        self.assertEqual(works[0]["code"], "3126001124/IC/123")

    def test_wrong_panchayat_and_duplicate_muster_are_rejected(self):
        with self.assertRaises(ValueError):
            parse_work(self.capture(), "3126001013")
        capture = self.capture()
        capture["tables"][0].append(capture["tables"][0][-1])
        with self.assertRaises(ValueError):
            parse_work(capture, "3126001124")

    def test_total_and_year_must_match_the_source(self):
        capture = self.capture()
        capture["text"] = capture["text"].replace("Rs. 1200", "Rs. 1300")
        with self.assertRaises(ValueError):
            parse_work(capture, "3126001124")
        capture = self.capture()
        capture["tables"][0][1][1]["text"] = "01/11/2025"
        with self.assertRaises(ValueError):
            parse_work(capture, "3126001124")

    def test_personal_housing_registration_is_not_republished_in_work_title(self):
        capture = self.capture()
        capture["tables"][0][0][0]["text"] = "Work Name: Construction PMAY-G REG. NO. UP-26-001/MMAYG123(3126001124/IF/IAY/123)"
        works, _ = parse_work(capture, "3126001124")
        self.assertEqual(works[0]["name"], "Individual housing work (PMAY-G)")

    def test_blank_is_missing_and_negative_or_rounded_extra_digits_are_rejected(self):
        self.assertIsNone(decimal_value(""))
        self.assertEqual(decimal_value("0"), "0")
        for value in ["-1", "NaN", "1.234", "1,200", "unknown"]:
            with self.assertRaises(ValueError):
                decimal_value(value)

    def test_only_official_sources_and_exact_spelling_normalization_are_accepted(self):
        self.assertEqual(normalized("BAGNERA- BAGNERI"), normalized("Bagnera Bagneri"))
        self.assertNotEqual(normalized("AMARIYA"), normalized("Amaria"))
        for url in ["https://vbgramgrep.dord.gov.in.evil.test/report", "https://user@vbgramgrep.dord.gov.in/report", "http://vbgramgrep.dord.gov.in/report"]:
            with self.assertRaises(ValueError):
                official(url)

if __name__ == "__main__":
    unittest.main()
