"""Validate and merge public Pilibhit eGramSwaraj captures without inferring geography."""
import argparse
import copy
import hashlib
import json
import re
from collections import defaultdict, Counter
from datetime import datetime
from decimal import Decimal
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import urljoin, urlparse, parse_qs

DAY = "2026-11-14"
YEAR = "2026-2027"
BASE = "https://egramswaraj.gov.in/"

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

def norm(value):
    return re.sub(r"[^a-z0-9]", "", value.casefold())

class Tables(HTMLParser):
    def __init__(self):
        super().__init__(convert_charrefs=True)
        self.stack, self.tables, self.text = [], [], []
        self.ignored = 0

    def handle_starttag(self, tag, attrs):
        attrs = dict(attrs)
        if tag in ("style", "script"):
            self.ignored += 1
        if tag == "table":
            self.stack.append({"rows": [], "row": None, "cell": None})
        elif self.stack:
            frame = self.stack[-1]
            if tag == "tr":
                frame["row"] = []
            elif tag in ("td", "th") and frame["row"] is not None:
                frame["cell"] = {"parts": [], "links": [], "colspan": int(attrs.get("colspan", "1"))}
            elif tag == "a" and frame["cell"] is not None and attrs.get("href"):
                frame["cell"]["links"].append(urljoin(BASE, attrs["href"]))

    def handle_data(self, value):
        if self.ignored:
            return
        self.text.append(value)
        if self.stack and self.stack[-1]["cell"] is not None:
            self.stack[-1]["cell"]["parts"].append(value)

    def handle_endtag(self, tag):
        if tag in ("style", "script"):
            self.ignored = max(0, self.ignored - 1)
        if not self.stack:
            return
        frame = self.stack[-1]
        if tag in ("td", "th") and frame["cell"] is not None:
            cell = frame["cell"]
            cell["text"] = " ".join("".join(cell.pop("parts")).split())
            frame["row"].append(cell)
            frame["cell"] = None
        elif tag == "tr" and frame["row"] is not None:
            frame["rows"].append(frame["row"])
            frame["row"] = None
        elif tag == "table":
            self.tables.append(self.stack.pop()["rows"])

def html(path):
    parser = Tables()
    parser.feed(path.read_text(encoding="utf8"))
    parser.close()
    if parser.stack:
        raise ValueError("Incomplete HTML table")
    parser.body = " ".join(" ".join(parser.text).split())
    return parser

def directory(path):
    source = json.loads(path.read_text())
    gps, villages = {}, {}
    for village in source["villages"]:
        if village["district_code"] != "173":
            continue
        villages[village["code"]] = village
        for gp in village.get("panchayats", []):
            item = gps.setdefault(gp["code"], {"names": set(), "blocks": set(), "villages": set()})
            item["names"].add(gp["name"])
            item["blocks"].update(b["name"] for b in village.get("blocks", []))
            item["villages"].add(village["code"])
    return gps, villages

def review_correspondence(source, employment, gps, villages, prior):
    groups = defaultdict(list)
    for row in source["rows"]:
        if len(row) != 19 or row[1] != "31" or row[4] != "3126":
            raise ValueError("Unexpected geography in correspondence capture")
        if row[6] == "3126001":
            groups[row[8]].append(row)
    records = []
    for gp in employment["panchayats"]:
        rows = groups[gp["code"]]
        candidates = sorted({r[15] for r in rows if r[15] and r[15] != "0"})
        item = {"employment_gp": gp["code"], "employment_name": gp["name"], "status": "held", "lgd_candidates": candidates,
                "source_rows": [r[0] for r in rows], "matched_villages": [], "conflicting_villages": [], "invalid_village_references": []}
        if not rows:
            item["reason"] = "No official correspondence rows for this employment Panchayat"
        elif len(candidates) != 1:
            item["reason"] = "Conflicting official LGD Panchayat codes"
        else:
            code = candidates[0]
            known = gps.get(code)
            item["gp"] = code
            if not known or len(known["names"]) != 1:
                item["reason"] = "LGD Panchayat code is absent or ambiguous in the accepted directory"
            elif known["blocks"] != {"Amariya"}:
                item["reason"] = "Official mapping points to a Panchayat in a different current block"
            else:
                item["name"] = next(iter(known["names"]))
                for row in rows:
                    village = row[17]
                    if village not in villages:
                        item["invalid_village_references"].append(village)
                    elif village not in known["villages"]:
                        item["conflicting_villages"].append(village)
                    else:
                        item["matched_villages"].append(village)
                for field in ["matched_villages", "conflicting_villages", "invalid_village_references"]:
                    item[field] = sorted(set(item[field]))
                if item["conflicting_villages"]:
                    item["reason"] = "Employment geography includes villages now belonging to other Panchayats"
                else:
                    item["status"] = "verified"
                    item["reason"] = "Explicit official employment-to-LGD Panchayat code, independently checked against current district and block"
                    item["village_evidence"] = "Matched accepted village codes" if item["matched_villages"] else "GP code verified; official village fields unusable; links use the accepted current LGD directory only"
        item["was_unmatched"] = gp["code"] not in prior
        records.append(item)
    return {"schema": 1, "source_url": source["source_url"], "source_sha256": source["original_sha256"], "reviewed_on": DAY,
            "records": records, "counts": dict(Counter(r["status"] for r in records)),
            "remaining_76": dict(Counter(r["status"] for r in records if r["was_unmatched"]))}

def monthly(path, plan):
    doc = html(path)
    for phrase in ["Month wise Summary Report", "Financial Year:-2026-2027", "District:-Pilibhit", "Report Completed"]:
        if phrase not in doc.body:
            raise ValueError("Missing monthly report context: " + phrase)
    match = re.search(r"Village Panchayat & Equivalent:-(.+?)(?= Month Receipts)", doc.body)
    if not match or norm(match[1]) != norm(plan["gp_nam"]):
        raise ValueError("Monthly report Panchayat name does not match the official code directory: " + str(plan["gp_cd"]) + " / " + str(match[1] if match else None))
    match = re.search(r"on [A-Za-z]+, ([A-Za-z]+ [0-9]+, [0-9]{4})", doc.body)
    if not match:
        raise ValueError("Missing monthly source-generation date")
    report_date = datetime.strptime(match[1], "%B %d, %Y").date().isoformat()
    if report_date > DAY:
        raise ValueError("Future monthly report")
    result, requests = [], []
    month_names = {"April":4,"May":5,"June":6,"July":7,"August":8,"September":9,"October":10,"November":11,"December":12,"January":1,"Januaury":1,"February":2,"March":3}
    for table in doc.tables:
        for row in table:
            texts = [c["text"] for c in row]
            if len(row) != 5:
                continue
            month = re.fullmatch(r"([A-Za-z]+), ([0-9]{4})", texts[0])
            if not month:
                continue
            month_number = month_names[month[1]]
            period = month[2] + "-" + str(month_number).zfill(2)
            counts = texts[1:]
            if not all(re.fullmatch(r"[0-9]+", value) for value in counts):
                raise ValueError("Non-integer voucher count")
            if period > report_date[:7]:
                if any(int(value) for value in counts):
                    raise ValueError("Non-zero future voucher counts")
                continue
            result.append(dict(month=period, **dict(zip(["receipts","payments","contra","journal"], map(int, counts)))))
            for link in row[0]["links"]:
                q = parse_qs(urlparse(link).query)
                if urlparse(link).hostname != "egramswaraj.gov.in" or urlparse(link).path != "/voucherWiseReport.do" or any(q.get(k) != [str(v)] for k,v in {"state":9,"district":164,"block":2021,"village":plan["gp_cd"],"finYear":YEAR,"month":month_number}.items()):
                    raise ValueError("Monthly voucher link has mismatched geography or period")
                requests.append({"gp":str(plan["gp_cd"]),"month":period,"url":link})
    if len(result) != len({r["month"] for r in result}) or len(result) != 8:
        raise ValueError("Incomplete or duplicate monthly rows")
    return result, report_date, requests

def activity(path, label, plan):
    data = json.loads(path.read_text(), parse_float=Decimal)
    match = re.fullmatch(r"(.*?)\s*\(([0-9]+)\)\s*", label)
    if not match or int(match[2]) != data.get("activityCd") or data.get("plnUntCd") != plan["gp_cd"] or data.get("fnclYr") != 2026:
        raise ValueError("Activity identity, Panchayat or financial year mismatch")
    if norm(data.get("activityName","")) != norm(match[1]):
        raise ValueError("Activity name differs from plan membership evidence")
    fields = {"name":"activityName","focus_area":"focusArea","type":"activityType","status":"activityStts","output_type":"outputTyp"}
    result = {"code":match[2], "plan_type":"Part of "+plan["plantype"]}
    for target,key in fields.items():
        value = data.get(key)
        if not isinstance(value,str) or not value.strip():
            raise ValueError("Missing activity field " + key)
        result[target] = value.strip()
    cost = data.get("totalCost")
    result["cost"] = str(cost) if isinstance(cost,(int,Decimal)) and cost >= 0 else None
    result["source_evidence"] = {"activity_code":match[2],"panchayat_code":str(plan["gp_cd"]),"financial_year":YEAR,"plan_id":str(plan["plan_cd"]),
                                "url":BASE+"activitySectionData.do?activityCd="+match[2],"sha256":digest(path),"retrieved_on":DAY}
    return result

def expand(captures, civil_path, package):
    gps, villages = directory(civil_path)
    plans = json.loads((captures/"official-plans-2021.json").read_text())
    main = [p for p in plans if p["plantype"] == "Main Plan"]
    if len(main) != 105 or len({p["gp_cd"] for p in main}) != 105 or any(p["zp_cd"]!=164 or p["parent_cd"]!=2021 or p["parent_name"]!="Amariya" for p in main):
        raise ValueError("Unexpected Pilibhit/Amariya plan directory")
    original = copy.deepcopy(package)
    existing = {(r["panchayat_code"],r["financial_year"]):r for r in package["reports"]}
    acquisitions, monthly_count, activity_count, activity_gps = [], 0, 0, []
    for plan in main:
        code = str(plan["gp_cd"])
        known = gps.get(code)
        if not known or len(known["names"])!=1 or known["blocks"]!={"Amariya"}:
            raise ValueError("eGramSwaraj GP code does not match the independent civil directory: "+code)
        key = (code,YEAR)
        if key not in existing:
            report = {"name":next(iter(known["names"])),"panchayat_code":code,"financial_year":YEAR,"block_name":"Amariya · Pilibhit · Uttar Pradesh","sources":{},
                      "mapping":{"verified":True,"state_lgd":"9","district_lgd":"173","village_lgd_codes":sorted(known["villages"]),
                                 "evidence":{"method":"Official eGramSwaraj numeric GP code and district/block hierarchy, independently checked against accepted LGD directory","civil_sha256":digest(civil_path),"plan_directory_sha256":digest(captures/"official-plans-2021.json")},
                                 "village_evidence":{"method":"Accepted LGD village-to-Panchayat membership","civil_sha256":digest(civil_path)}}}
            package["reports"].append(report)
            existing[key] = report
        report = existing[key]
        path = captures/("official-monthly-"+code+".html")
        months, report_date, requests = monthly(path, plan)
        if report.get("months"):
            report.setdefault("previous_monthly_snapshots",[]).append({"source":report["sources"].get("monthly"),"months":report["months"]})
        report["sources"]["monthly"] = {"url":BASE+"FileRedirect.jsp?FD=FinancialYear2026-2027/9&name="+code+".html","report_date":report_date,
                                       "retrieved_on":DAY,"capture_sha256":digest(path),"report_date_basis":"Official source-generation date"}
        report["months"] = months
        acquisitions.extend(requests)
        monthly_count += 1
        list_path = captures/("official-activities-"+code+"-"+str(plan["plan_cd"])+".json")
        if list_path.is_file():
            labels = json.loads(list_path.read_text())
            activities = []
            for label in labels:
                match = re.search(r"\(([0-9]+)\)\s*$",label)
                if not match:
                    raise ValueError("Unrecognised activity label")
                activities.append(activity(captures/("official-activity-"+match[1]+".json"),label,plan))
            if len({a["code"] for a in activities}) != len(activities):
                raise ValueError("Duplicate activity code")
            if report.get("activities"):
                raise ValueError("Existing activities must not be silently replaced")
            report["activities"] = activities
            report["sources"]["activities"] = {"url":BASE+"getActivityList.do?planCd="+str(plan["plan_cd"])+"&activityStts=0","report_date":DAY,
                                               "retrieved_on":DAY,"capture_sha256":digest(list_path),"report_date_basis":"Capture date; source provides no generation date"}
            activity_count += len(activities)
            activity_gps.append(code)
    package["reports"].sort(key=lambda row:(row["name"],row["financial_year"]))
    for before in original["reports"]:
        after=existing[(before["panchayat_code"],before["financial_year"])]
        for field in ["payments","receipts","contra","journal","activities"]:
            if before.get(field) and before[field] != after.get(field):
                raise ValueError("Existing published records changed: "+field)
    return package, acquisitions, {"monthly_panchayats":monthly_count,"new_activity_panchayats":activity_gps,"new_activities":activity_count}

def rural_candidate(old, captures, prior_captures, review, civil_path):
    gps, villages = directory(civil_path)
    finance_path = prior_captures/"amariya-finance.json"
    finance = json.loads(finance_path.read_text())
    employment_path = prior_captures/"amariya-directory.json"
    employment = json.loads(employment_path.read_text())
    by_name = {norm(g["name"]):g for g in employment["panchayats"]}
    audited = {row["employment_gp"]:row for row in review["records"]}
    previous = {row["employment_gp"]:row for row in old["reports"]}
    fields = {9:"unskilled_wage_expenditure",10:"skilled_wage_expenditure",11:"material_expenditure",16:"total_expenditure",18:"unskilled_wage_due",19:"skilled_wage_due",20:"material_due",22:"total_due"}
    reports, held = [], []
    for table in finance["tables"]:
        for cells in table:
            if len(cells)!=23 or not cells[0]["text"].isdigit() or cells[1]["text"].isdigit():
                continue
            name = cells[1]["text"]
            identity = by_name.get(norm(name))
            if not identity:
                raise ValueError("Financial row not uniquely identified in employment directory")
            item = audited[identity["code"]]
            values = {}
            for i,key in fields.items():
                value = cells[i]["text"]
                if value and not re.fullmatch(r"[0-9]+(?:\.[0-9]{1,2})?",value):
                    raise ValueError("Invalid financial source amount")
                values[key] = value or None
            if item["status"] != "verified":
                held.append({"employment_gp":identity["code"],"official_name":name,"reason":item["reason"],"lgd_candidates":item["lgd_candidates"],"finance":values,
                             "financial_year":YEAR,"finance_source":finance["url"],"finance_capture_sha256":digest(finance_path)})
                continue
            code=item["gp"]; known=gps[code]
            row = copy.deepcopy(previous.get(identity["code"],{}))
            row.update({"state":"9","district":"173","gp":code,"name":next(iter(known["names"])),"official_name":name,"employment_gp":identity["code"],
                        "block":"Amariya","villages":sorted(known["villages"]),"financial_year":YEAR,"scheme":"VB-G RAM G","as_of":DAY,"captured_on":DAY,
                        "unit":"INR_lakh_rounded_2dp","finance":values,"finance_source":finance["url"],"finance_capture_sha256":digest(finance_path)})
            row.setdefault("works",[])
            row["mapping"] = {"verified":True,"method":item["reason"],"civil_directory_sha256":digest(civil_path),"employment_directory_capture_sha256":digest(employment_path),
                              "correspondence_source_url":review["source_url"],"correspondence_source_sha256":review["source_sha256"],"source_rows":item["source_rows"],
                              "matched_villages":item["matched_villages"],"reviewed_on":DAY}
            reports.append(row)
    if len(reports)+len(held)!=105 or len({r["gp"] for r in reports})!=len(reports):
        raise ValueError("Unaccounted or duplicate published Panchayat mapping")
    result = copy.deepcopy(old)
    result["reports"] = sorted(reports,key=lambda row:row["name"])
    result["held_reports"] = held
    result["scope"].update({"published_panchayats":len(reports),"unresolved_names":[r["official_name"] for r in held]})
    result["correspondence_review"] = {"source_url":review["source_url"],"source_sha256":review["source_sha256"],"reviewed_on":DAY,"counts":review["counts"],"remaining_76":review["remaining_76"]}
    return result

def make_review(captures, prior_captures, civil_path, rural):
    gps, villages=directory(civil_path)
    source=json.loads((captures/"official-pilibhit-lgd-correspondence.json").read_text())
    employment=json.loads((prior_captures/"amariya-directory.json").read_text())
    previous={r["employment_gp"]:r for r in rural["reports"]}
    review=review_correspondence(source,employment,gps,villages,previous)
    for item in review["records"]:
        if item["status"]=="held" and not item["source_rows"] and item["employment_gp"] in previous:
            old=previous[item["employment_gp"]]
            known=gps.get(old["gp"])
            if known and len(known["names"])==1 and known["blocks"]=={"Amariya"} and norm(item["employment_name"])==norm(next(iter(known["names"]))):
                item.update({"status":"verified","gp":old["gp"],"name":next(iter(known["names"])),"reason":"Existing unique exact name and accepted current hierarchy retained; no conflicting official correspondence rows"})
    reverse=defaultdict(list)
    for item in review["records"]:
        if item["status"]=="verified":
            reverse[item["gp"]].append(item)
    for rows in reverse.values():
        if len(rows)>1:
            for item in rows:
                item.update({"status":"held","reason":"Multiple employment Panchayats map to the same current LGD Panchayat"})
    review["counts"]=dict(Counter(r["status"] for r in review["records"]))
    review["remaining_76"]=dict(Counter(r["status"] for r in review["records"] if r["was_unmatched"]))
    return review


def money(text):
    if "," in text and not re.fullmatch(r"(?:[0-9]{1,3}(?:,[0-9]{3})+|[0-9]{1,2}(?:,[0-9]{2})*,[0-9]{3})(?:\.[0-9]{1,2})?",text):
        raise ValueError("Malformed comma grouping in rupee amount")
    value=text.replace(",","").strip()
    if not re.fullmatch(r"[0-9]+(?:\.[0-9]{1,2})?",value):
        raise ValueError("Missing or invalid rupee amount")
    return int(Decimal(value)*100)

def voucher_entries(path, request):
    doc=html(path)
    if "Voucher Wise Summary Report" not in doc.body or "Voucher Amount (in Rs.)" not in doc.body:
        raise ValueError("Unrecognised voucher report or amount unit")
    table=next((t for t in doc.tables if any([c["text"] for c in r][:2]==["Receipts","Payments"] for r in t)),None)
    if table is None:
        raise ValueError("Missing voucher type header")
    reported=re.search(r"on [A-Za-z]+, ([0-9]{1,2} [A-Za-z]+ [0-9]{4})",doc.body)
    if not reported:
        raise ValueError("Missing voucher report generation date")
    date=datetime.strptime(reported[1],"%d %b %Y").date().isoformat()
    if date>DAY:
        raise ValueError("Future voucher report")
    result=[]
    for row_index,row in enumerate(table):
        if len(row)!=15:
            continue
        for kind,date_index,number_index,type_index,amount_index in [("receipts",0,1,2,3),("payments",4,5,6,7),("contra",9,10,None,11),("journal",12,13,None,14)]:
            voucher=row[number_index]["text"]
            entered=row[date_index]["text"]
            if not voucher and not entered:
                continue
            if entered=="Voucher Date":
                continue
            parsed=datetime.strptime(entered,"%d/%m/%Y").date().isoformat()
            if parsed[:7]!=request["month"] or parsed>date or not voucher:
                raise ValueError("Voucher date, period or identity mismatch")
            links=row[number_index]["links"]
            detail=None
            expected_path={"receipts":"/receiptVoucherDetail.do","payments":"/paymentVoucherDetail.do"}.get(kind)
            if expected_path:
                if len(links)!=1:
                    raise ValueError("Expected one voucher-detail reference")
                detail=links[0];url=urlparse(detail);q=parse_qs(url.query)
                if url.scheme!="https" or url.hostname!="egramswaraj.gov.in" or url.path!=expected_path or any(q.get(k)!=[str(v)] for k,v in {"PRIEntity_code":request["gp"],"stateCode":9,"finYear":YEAR}.items()) or not q.get("voucherID",[""])[0].isdigit():
                    raise ValueError("Voucher detail has mismatched identity")
            result.append({"kind":kind,"date":parsed,"voucher":voucher,"voucher_type":row[type_index]["text"] if type_index is not None else None,
                           "amount_paise":money(row[amount_index]["text"]),"source_key":"vouchers_"+request["month"].replace("-","_"),
                           "_detail_url":detail,"_source_row":row_index+1,"_report_date":date,"_gp":request["gp"]})
    return result

def add_vouchers(package, captures, requests):
    reports={(r["panchayat_code"],r["financial_year"]):r for r in package["reports"]}
    grouped=defaultdict(list)
    for request in requests:
        path=captures/("official-vouchers-"+request["gp"]+"-"+request["month"]+".html")
        if not path.is_file():
            raise ValueError("Missing voucher capture: "+path.name)
        rows=voucher_entries(path,request)
        grouped[request["gp"]].extend(rows)
        key="vouchers_"+request["month"].replace("-","_")
        reports[(request["gp"],YEAR)]["sources"][key]={"url":request["url"],"report_date":rows[0]["_report_date"] if rows else DAY,"retrieved_on":DAY,"capture_sha256":digest(path)}
    details=[]
    count=0
    for gp,rows in grouped.items():
        counts=Counter((r["kind"],r["voucher"]) for r in rows)
        report=reports[(gp,YEAR)]
        for row in rows:
            if counts[(row["kind"],row["voucher"])]>1:
                if not row["_detail_url"]:
                    raise ValueError("Ambiguous non-receipt/payment voucher")
                detail_id=parse_qs(urlparse(row["_detail_url"]).query)["voucherID"][0]
                name="official-detail-"+gp+"-"+row["kind"]+"-"+detail_id+".html"
                path=captures/name
                if not path.is_file():
                    details.append({"url":row["_detail_url"],"file":name})
                    continue
                evidence=html(path)
                if row["voucher"] not in evidence.body or datetime.strptime(row["date"],"%Y-%m-%d").strftime("%d/%m/%Y") not in evidence.body:
                    raise ValueError("Detailed voucher identity or date differs")
                numbers=[money(m) for m in re.findall(r"(?<![A-Za-z0-9])(?:[0-9]+,)*[0-9]+(?:\.[0-9]{1,2})?(?![A-Za-z0-9])",evidence.body)]
                if row["amount_paise"] not in numbers:
                    raise ValueError("Detailed voucher amount differs")
                row["record_source"]={"url":row["_detail_url"],"sha256":digest(path),"voucher":row["voucher"],"date":row["date"],"amount_paise":row["amount_paise"]}
            published={k:v for k,v in row.items() if not k.startswith("_") and k!="kind"}
            target=report.setdefault(row["kind"],[])
            if any(v["voucher"]==row["voucher"] and v["date"]==row["date"] and v.get("amount_paise")==row["amount_paise"] and v.get("source_key")==row["source_key"] for v in target):
                raise ValueError("Attempted repeated voucher import")
            target.append(published);count+=1
    return count,details

def main():
    parser=argparse.ArgumentParser()
    parser.add_argument("--captures",type=Path,required=True)
    parser.add_argument("--prior-captures",type=Path,required=True)
    parser.add_argument("--civil",type=Path,required=True)
    parser.add_argument("--accounts",type=Path,required=True)
    parser.add_argument("--rural",type=Path,required=True)
    parser.add_argument("--output",type=Path,required=True)
    args=parser.parse_args()
    args.output.mkdir(exist_ok=True)
    old_rural=json.loads(args.rural.read_text())
    review=make_review(args.captures,args.prior_captures,args.civil,old_rural)
    rural=rural_candidate(old_rural,args.captures,args.prior_captures,review,args.civil)
    accounts,requests,summary=expand(args.captures,args.civil,json.loads(args.accounts.read_text()))
    voucher_requests=[r for r in requests if r["gp"] in {str(n) for n in range(81737,81747)}]
    voucher_count,missing_details=add_vouchers(accounts,args.captures,voucher_requests)
    if missing_details:
        (args.output/"voucher-detail-requests.json").write_text(json.dumps(missing_details,indent=2)+"\n")
        raise ValueError("Detailed evidence is required for ambiguous voucher numbers")
    summary["new_voucher_entries"]=voucher_count
    summary["new_voucher_panchayats"]=len({r["gp"] for r in voucher_requests})
    artifacts={"pilibhit-employment-correspondence.json":review,"pilibhit-rural-employment.json":rural,"egramswaraj-accounts.json":accounts,
               "voucher-requests.json":[r for r in requests if r["gp"] in {str(n) for n in range(81737,81747)}]}
    for name,data in artifacts.items():
        (args.output/name).write_text(json.dumps(data,ensure_ascii=False,indent=2)+"\n")
    summary.update({"employment_review":review["counts"],"remaining_76_review":review["remaining_76"],"rural_panchayats":len(rural["reports"]),"held_financial_records":len(rural["held_reports"])})
    (args.output/"summary.json").write_text(json.dumps(summary,indent=2)+"\n")
    import csv
    with (args.output/"name-review.csv").open("w",newline="") as f:
        writer=csv.writer(f);writer.writerow(["employment_code","employment_name","status","lgd_panchayat","current_name","reason","village_conflicts"])
        for item in review["records"]:
            writer.writerow([item["employment_gp"],item["employment_name"],item["status"],item.get("gp",""),item.get("name",""),item["reason"],";".join(item["conflicting_villages"])])
    print(json.dumps(summary))

if __name__=="__main__":
    main()
