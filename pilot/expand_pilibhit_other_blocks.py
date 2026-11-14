"""Expand independently verified Pilibhit block reports from public official captures."""
import argparse
import copy
import json
import re
from collections import Counter
from pathlib import Path
from expand_pilibhit_panchayat_reports import BASE, DAY, YEAR, digest, directory, norm, monthly, activity, add_vouchers

def verify_plan(plan, gps, state_rows):
    code = str(plan["gp_cd"])
    gp = gps.get(code)
    identities = [r for r in state_rows if r[4] == code and r[0] == "PILIBHIT"]
    if not gp or len(gp["names"]) != 1 or gp["blocks"] != {plan["parent_name"]}:
        raise ValueError("Civil GP/block membership mismatch: "+code)
    if plan["zp_cd"] != 164 or plan["parent_cd"] not in range(2022,2028) or plan["plantype"] != "Main Plan":
        raise ValueError("Unexpected district or plan hierarchy")
    if len(identities) != 1 or identities[0][3] != str(plan["parent_cd"]) or norm(identities[0][1]) != norm(plan["parent_name"]) or not identities[0][2].strip():
        raise ValueError("Independent UP Panchayati Raj identity mismatch: "+code)
    return gp

def build(captures, civil, original):
    gps, villages = directory(civil)
    state = json.loads((captures/"official-up-panchayat-identities.json").read_text())
    plans = json.loads((captures/"plans.json").read_text())
    if len({p["gp_cd"] for p in plans}) != len(plans):
        raise ValueError("Duplicate Panchayat plan identity")
    out = copy.deepcopy(original)
    existing = {(r["panchayat_code"],r["financial_year"]):r for r in out["reports"]}
    requests, statuses, activity_count = [], [], 0
    selected = {str(p["gp_cd"]) for p in json.loads((captures/"activity-plans.json").read_text())}
    for plan in plans:
        code = str(plan["gp_cd"])
        try:
            gp = verify_plan(plan, gps, state["rows"])
            path = captures/("official-monthly-"+code+".html")
            months, generated, links = monthly(path,plan)
        except (ValueError, FileNotFoundError) as error:
            statuses.append({"gp":code,"block":plan["parent_name"],"status":"held","reason":str(error)})
            continue
        key = (code,YEAR)
        report = existing.get(key)
        if report is None:
            report = {"name":next(iter(gp["names"])),"panchayat_code":code,"financial_year":YEAR,
                      "block_name":plan["parent_name"]+" · Pilibhit · Uttar Pradesh","sources":{},
                      "mapping":{"verified":True,"state_lgd":"9","district_lgd":"173","village_lgd_codes":sorted(gp["villages"]),
                                 "evidence":{"method":"Official eGram numeric GP and district/block hierarchy, independently checked against UP Panchayati Raj and accepted LGD directory","civil_sha256":digest(civil),"state_directory_sha256":digest(captures/"official-up-pilibhit-panchayats.html"),"plan_directory_sha256":digest(captures/("official-plans-"+str(plan["parent_cd"])+".json"))},
                                 "village_evidence":{"method":"Accepted LGD village-to-Panchayat membership","civil_sha256":digest(civil)}}}
            out["reports"].append(report);existing[key]=report
        if report.get("months"):
            report.setdefault("previous_monthly_snapshots",[]).append({"source":report["sources"].get("monthly"),"months":report["months"]})
        report["months"] = months
        report["sources"]["monthly"] = {"url":BASE+"FileRedirect.jsp?FD=FinancialYear2026-2027/9&name="+code+".html","report_date":generated,
                                        "retrieved_on":DAY,"capture_sha256":digest(path),"report_date_basis":"Official source-generation date"}
        if code in selected:
            requests.extend(links)
            list_path=captures/("official-activities-"+code+"-"+str(plan["plan_cd"])+".json")
            if list_path.is_file():
                labels=json.loads(list_path.read_text())
                activities=[]
                try:
                    for label in labels:
                        match=re.fullmatch(r"(.*?)\s*\(([0-9]+)\)\s*",label)
                        if not match:raise ValueError("Unrecognised activity membership")
                        activities.append(activity(captures/("official-activity-"+match[2]+".json"),label,plan))
                    if len({a["code"] for a in activities})!=len(activities):raise ValueError("Duplicate activity identity")
                    if report.get("activities"):raise ValueError("Existing activities cannot be replaced")
                    report["activities"]=activities
                    report["sources"]["activities"]={"url":BASE+"getActivityList.do?planCd="+str(plan["plan_cd"])+"&activityStts=0","report_date":DAY,"retrieved_on":DAY,"capture_sha256":digest(list_path),"report_date_basis":"Capture date; source provides no generation date"}
                    activity_count+=len(activities)
                except (ValueError,FileNotFoundError) as error:
                    statuses.append({"gp":code,"block":plan["parent_name"],"status":"activity-held","reason":str(error)})
        statuses.append({"gp":code,"block":plan["parent_name"],"status":"verified","months":len(months)})
    for before in original["reports"]:
        after=existing[(before["panchayat_code"],before["financial_year"])]
        for field in ["payments","receipts","contra","journal","activities"]:
            if before.get(field) and before[field]!=after.get(field):raise ValueError("Existing published data changed: "+field)
    out["reports"].sort(key=lambda r:(r["name"],r["financial_year"]))
    return out, requests, statuses, activity_count

def recheck_held_names(review, captures, civil):
    """Corroborate identities without overriding contradictory employment geography."""
    result = copy.deepcopy(review)
    source = json.loads((captures/"official-up-panchayat-identities.json").read_text())
    _, villages = directory(civil)
    rows = [r for r in source["rows"] if r[0] == "PILIBHIT" and r[1] == "AMARIYA"]
    for row in result["records"]:
        if row["status"] != "held" or not row["was_unmatched"]:
            continue
        exact = [r for r in rows if norm(r[2]) == norm(row["employment_name"])]
        codes = [r for r in rows if r[4] in row.get("lgd_candidates", [])]
        item = lambda r: {"name":r[2],"gp":r[4],"block":r[1]}
        row["secondary_review"] = {"reviewed_on":DAY,"source_url":source["source"],
            "source_sha256":digest(captures/"official-up-pilibhit-panchayats.html"),
            "official_name_matches":[item(r) for r in exact],"official_code_matches":[item(r) for r in codes],
            "conflict_details":[{"village":v,"name":villages.get(v,{}).get("name"),"current_panchayats":villages.get(v,{}).get("panchayats",[])} for v in row["conflicting_villages"]],
            "decision":"Held: state Panchayat identity directory does not establish a corrected employment-code/village correspondence. Existing contradictory or missing employment mapping is not overridden by name similarity."}
    return result

def main():
    p=argparse.ArgumentParser();p.add_argument("--captures",type=Path,required=True);p.add_argument("--civil",type=Path,required=True);p.add_argument("--before",type=Path,required=True);p.add_argument("--output",type=Path,required=True);p.add_argument("--vouchers",action="store_true")
    args=p.parse_args();args.output.mkdir(exist_ok=True)
    original=json.loads(args.before.read_text())
    package,requests,statuses,count=build(args.captures,args.civil,original)
    existing_vouchers={r["panchayat_code"] for r in original["reports"] if r["financial_year"]==YEAR and any(r.get(k) for k in ["payments","receipts","contra","journal"])}
    requests=[r for r in requests if r["gp"] not in existing_vouchers]
    (args.output/"voucher-requests.json").write_text(json.dumps(requests,indent=2))
    voucher_count=0;details=[]
    if args.vouchers:
        voucher_count,details=add_vouchers(package,args.captures,requests)
        if details:
            (args.output/"voucher-detail-requests.json").write_text(json.dumps(details,indent=2))
            raise ValueError("Ambiguous voucher identity requires detailed evidence")
    if not details:
        (args.output/"egramswaraj-accounts.json").write_text(json.dumps(package,ensure_ascii=False,indent=2)+"\n")
    review_path=args.captures/"before-pilibhit-employment-correspondence.json"
    if review_path.is_file():
        review=recheck_held_names(json.loads(review_path.read_text()),args.captures,args.civil)
        (args.output/"pilibhit-employment-correspondence.json").write_text(json.dumps(review,ensure_ascii=False,indent=2)+"\n")
    (args.output/"import-review.json").write_text(json.dumps(statuses,indent=2))
    summary={"monthly_by_block":dict(Counter(s["block"] for s in statuses if s["status"]=="verified")),"new_activities":count,"new_vouchers":voucher_count,"held":dict(Counter(s["status"] for s in statuses if s["status"]!="verified"))}
    (args.output/"summary.json").write_text(json.dumps(summary,indent=2));print(json.dumps(summary))
if __name__=="__main__":main()
