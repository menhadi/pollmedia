import copy
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from build_pilibhit_detail_shards import merge_vouchers, detail_proof, same_facts

class PanchayatDetailMergeTest(unittest.TestCase):
    def row(self, date="2026-04-01", amount=100, identity="1"):
        return {"kind":"payments","voucher":"P/1","date":date,"amount_paise":amount,"voucher_type":"Payment","source_key":"vouchers_2026_04",
                "_detail_url":"https://egramswaraj.gov.in/paymentVoucherDetail.do?PRIEntity_code=81720&stateCode=9&finYear=2026-2027&voucherID="+identity}
    def proof(self,row,*_):
        return {"url":row["_detail_url"],"sha256":"a"*64,"voucher":row["voucher"],"date":row["date"],"amount_paise":row["amount_paise"]},None

    def test_repeated_import_preserves_existing_facts_and_does_not_duplicate(self):
        r={"panchayat_code":"81720"}
        row=self.row()
        self.assertEqual(merge_vouchers(r,[copy.deepcopy(row)],Path("."))[0],1)
        old=copy.deepcopy(r["payments"])
        self.assertEqual(merge_vouchers(r,[copy.deepcopy(row)],Path("."))[0],0)
        self.assertEqual(r["payments"],old)

    def test_changed_amount_is_held_and_original_remains(self):
        r={"panchayat_code":"81720","payments":[{"voucher":"P/1","date":"2026-04-01","amount_paise":100,"source_key":"vouchers_2026_04"}]}
        before=copy.deepcopy(r)
        with patch("build_pilibhit_detail_shards.detail_proof",side_effect=self.proof):
            count,held,requests=merge_vouchers(r,[self.row(amount=200)],Path("."))
        self.assertEqual(count,0);self.assertEqual(len(held),1)
        self.assertEqual(r,before);self.assertEqual(requests,[])

    def test_reused_number_requires_source_ids_and_can_upgrade_existing_evidence_independent_of_order(self):
        r={"panchayat_code":"81720","payments":[{"voucher":"P/1","date":"2026-04-01","amount_paise":100,"source_key":"vouchers_2026_04"}]}
        old=copy.deepcopy(r["payments"][0])
        rows=[self.row("2026-05-01",200,"2"),self.row()]
        with patch("build_pilibhit_detail_shards.detail_proof",side_effect=self.proof):
            count,held,requests=merge_vouchers(r,rows,Path("."))
        self.assertEqual(count,1);self.assertEqual(held,[]);self.assertEqual(requests,[])
        self.assertEqual(len(r["payments"]),2);self.assertTrue(same_facts(old,r["payments"][0]))
        self.assertEqual(r["payments"][0]["record_source"]["url"],self.row()["_detail_url"])

    def test_unavailable_detail_proof_never_publishes_ambiguous_rows(self):
        with tempfile.TemporaryDirectory() as d:
            r={"panchayat_code":"81720"}
            count,held,requests=merge_vouchers(r,[self.row(),self.row("2026-05-01",200,"2")],Path(d))
            self.assertEqual(count,0);self.assertEqual(len(requests),2)
            self.assertEqual(r.get("payments",[]),[])

    def test_detail_proof_checks_official_voucher_date_and_amount(self):
        with tempfile.TemporaryDirectory() as d:
            path=Path(d)/"official-detail-81720-payments-1.html"
            path.write_text("<table><tr><td>Voucher Date</td><td>01/04/2026</td><td>Voucher No</td><td>P/1</td></tr><tr><td>Amount (in Rs.)</td><td>1.00</td></tr></table>")
            proof,request=detail_proof(self.row(),"81720",Path(d))
            self.assertEqual(proof["amount_paise"],100);self.assertIsNone(request)
            path.write_text("<table><tr><td>Voucher Date</td><td>01/04/2026</td><td>Voucher No</td><td>P/1</td></tr><tr><td>Amount (in Rs.)</td><td>2.00</td></tr></table>")
            with self.assertRaises(ValueError):detail_proof(self.row(),"81720",Path(d))

    def test_receipt_inline_labels_bind_amount_instead_of_arbitrary_document_numbers(self):
        with tempfile.TemporaryDirectory() as d:
            row=self.row();row["kind"]="receipts"
            row["_detail_url"]=row["_detail_url"].replace("paymentVoucherDetail","receiptVoucherDetail")
            path=Path(d)/"official-detail-81720-receipts-1.html"
            path.write_text("<table><tr><td>Voucher Date : 01/04/2026</td><td>Voucher No : P/1</td></tr><tr><td>Cheque No : 99</td><td>Amount (in Rs.) : 1.00</td></tr></table>")
            proof,_=detail_proof(row,"81720",Path(d))
            self.assertEqual(proof["amount_paise"],100)

if __name__=="__main__":unittest.main()
