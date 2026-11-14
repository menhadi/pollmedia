"""Build a reviewed Pilibhit rural-employment pilot from captured public reports."""
import argparse
import hashlib
import json
import re
from collections import defaultdict
from datetime import datetime
from decimal import Decimal
from pathlib import Path
from urllib.parse import urlparse

def normalized(value):
    return re.sub(r"[^a-z0-9]", "", value.casefold())

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def official(url):
    parsed = urlparse(url)
    if parsed.scheme != "https" or parsed.hostname not in {"vbgramgrep.dord.gov.in", "mnregaweb4.nic.in"} or parsed.username or parsed.password:
        raise ValueError("Untrusted report address")
    return url

def decimal_value(text):
    if text == "":
        return None
    if not re.fullmatch(r"[0-9]+(?:\.[0-9]{1,2})?", text):
        raise ValueError("Unexpected financial amount: " + text)
    return text

def parse_work(capture, gp_code):
    text = capture["text"]
    if "Expenditure on Muster Roll" not in text or "2026-2027" not in text or "Report Completed" not in text:
        raise ValueError("Incomplete work expenditure report")
    if not all(word in text for word in ["PILIBHIT", "AMARIYA", "Amount on UnSkilled(In Rupees)"]):
        raise ValueError("Work geography or amount unit not established")
    works, current, seen = [], None, set()
    for table in capture["tables"]:
        for row in table:
            cells = [cell["text"] for cell in row]
            if len(cells) == 1 and cells[0].startswith("Work Name:"):
                match = re.fullmatch(r"Work Name:\s*(.*?)\s*\((" + re.escape(gp_code) + r"/[A-Za-z0-9/_.-]+)\)", cells[0])
                if not match:
                    raise ValueError("Work code does not belong to this Panchayat")
                name, code = match.groups()
                if any(work["code"] == code for work in works):
                    raise ValueError("Duplicate work code")
                if re.search(r"REG\.?\s*NO|MMAYG|UP-[0-9]", name, re.I):
                    name = "Individual housing work (PMAY-G)"
                current = {"code": code, "name": name, "musters": []}
                works.append(current)
            elif len(cells) == 3 and re.fullmatch(r"[0-9]+", cells[0]):
                if current is None:
                    raise ValueError("Muster without work header")
                date = datetime.strptime(cells[1], "%d/%m/%Y").date().isoformat()
                if not "2026-04-01" <= date <= "2027-03-31":
                    raise ValueError("Payment outside report year")
                if (current["code"], cells[0]) in seen:
                    raise ValueError("Duplicate muster")
                seen.add((current["code"], cells[0]))
                amount = Decimal(decimal_value(cells[2]))
                current["musters"].append({"number": cells[0], "reported_payment_date": date, "amount_paise": int(amount * 100)})
    total = sum(m["amount_paise"] for work in works for m in work["musters"])
    reported = re.search(r"Grand Total\s+Rs\.\s*([0-9.]+)", text)
    if not works or not reported or int(Decimal(reported[1]) * 100) != total:
        raise ValueError("Work amounts do not reconcile to the source total")
    for work in works:
        work["amount_paise"] = sum(m["amount_paise"] for m in work["musters"])
    return works, total

def build(captures, directory_file):
    civil = json.loads(directory_file.read_text())
    directory = json.loads((captures / "amariya-directory.json").read_text())
    finance_path = captures / "amariya-finance.json"
    finance = json.loads(finance_path.read_text())
    if directory["state"] != {"code": "31", "name": "UTTAR PRADESH"} or directory["district"] != {"code": "3126", "name": "PILIBHIT"} or directory["block"] != {"code": "3126001", "name": "AMARIYA"}:
        raise ValueError("Unexpected official employment directory")
    if not all(part in finance["text"] for part in ["2026-2027", "PILIBHIT", "AMARIYA", "Rs. in Lakhs", "Report Completed", "November 14, 2026"]):
        raise ValueError("Financial source context or units missing")
    official(finance["url"]); official(directory["url"])
    gps = defaultdict(lambda: {"names": set(), "blocks": set(), "villages": set()})
    for village in civil["villages"]:
        if village["district_code"] != "173":
            continue
        for gp in village.get("panchayats", []):
            gps[gp["code"]]["names"].add(gp["name"])
            gps[gp["code"]]["blocks"].update(block["name"] for block in village.get("blocks", []))
            gps[gp["code"]]["villages"].add(village["code"])
    by_name = defaultdict(list)
    for code, item in gps.items():
        if len(item["names"]) == 1 and item["blocks"] == {"Amariya"}:
            by_name[normalized(next(iter(item["names"])))].append(code)
    official_names = defaultdict(list)
    for gp in directory["panchayats"]:
        official_names[normalized(gp["name"])].append(gp)
    result, held = [], []
    fields = {9:"unskilled_wage_expenditure",10:"skilled_wage_expenditure",11:"material_expenditure",16:"total_expenditure",18:"unskilled_wage_due",19:"skilled_wage_due",20:"material_due",22:"total_due"}
    data_rows = [r for t in finance["tables"] for r in t if len(r) == 23 and re.fullmatch(r"[0-9]+", r[0]["text"]) and not r[1]["text"].isdigit()]
    if len(data_rows) != len(directory["panchayats"]):
        raise ValueError("Incomplete financial rows")
    seen = set()
    for row in data_rows:
        name = row[1]["text"]
        key = normalized(name)
        matches = by_name.get(key, [])
        candidates = official_names.get(key, [])
        if len(matches) != 1 or len(candidates) != 1 or key in seen:
            held.append(name); continue
        seen.add(key)
        lgd, gp = matches[0], candidates[0]
        values = {field:decimal_value(row[i]["text"]) for i,field in fields.items()}
        result.append({"state":"9","district":"173","gp":lgd,"name":next(iter(gps[lgd]["names"])),"official_name":name,"employment_gp":gp["code"],"block":"Amariya","villages":sorted(gps[lgd]["villages"]),"mapping":{"verified":True,"method":"Unique exact normalized Panchayat name within independently verified Uttar Pradesh / Pilibhit / Amariya hierarchy; punctuation and spaces only ignored; no fuzzy matches","civil_directory_sha256":digest(directory_file),"employment_directory_capture_sha256":digest(captures / "amariya-directory.json")},"financial_year":"2026-2027","scheme":"VB-G RAM G","as_of":"2026-11-14","captured_on":"2026-11-14","unit":"INR_lakh_rounded_2dp","finance":values,"finance_source":finance["url"],"finance_capture_sha256":digest(finance_path),"works":[]})
    for name, code in [("bagnera","3126001124"),("balliya","3126001013")]:
        path = captures / (name + "-work-payments.json")
        capture = json.loads(path.read_text())
        official(capture["url"])
        matches = [row for row in result if row["employment_gp"] == code]
        if len(matches) != 1:
            raise ValueError("Detailed work Panchayat not uniquely mapped")
        row = matches[0]
        row["works"], total = parse_work(capture, code)
        if abs(Decimal(row["finance"]["unskilled_wage_expenditure"]) * 10000000 - total) > 50000:
            raise ValueError("Work total differs from rounded financial report")
        row["work_source"] = capture["url"]
        row["work_capture_sha256"] = digest(path)
    return {"schema":1,"publication":{"enabled":True,"basis":"User-authorized public numerical facts from inspected official reports; reviewed exact hierarchy correspondence","government_endorsement":False},"job_card_lookup":"https://mnregaweb4.nic.in/netnrega/workers/wrkinfo.aspx?lang=en","scope":{"state":"9","district":"173","employment_block":"3126001","name":"Amariya, Pilibhit","financial_rows":len(data_rows),"published_panchayats":len(result),"unresolved_names":held},"reports":sorted(result,key=lambda row:row["name"])}

if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--captures", type=Path, required=True)
    parser.add_argument("--directory", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    package = build(args.captures, args.directory)
    args.output.write_text(json.dumps(package, ensure_ascii=False, indent=2) + "\n")
    print(json.dumps(package["scope"]))
