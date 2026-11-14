"""Build code-verified Panchayat detail shards without replacing published facts."""
import argparse
import copy
import json
import re
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path
from urllib.parse import parse_qs, urlparse

from expand_pilibhit_panchayat_reports import BASE, DAY, YEAR, activity, digest, directory, html, money, monthly, voucher_entries

KINDS = ["payments", "receipts", "contra", "journal"]

def same_facts(old, new):
    return all(new.get(key) == value for key, value in old.items())

def record_id(row):
    url = row.get("record_source", {}).get("url", row.get("_detail_url") or "")
    return parse_qs(urlparse(url).query).get("voucherID", [None])[0]

def detail_proof(row, gp, captures):
    if not row["_detail_url"]:
        raise ValueError("Repeated transfer/journal identity has no detailed official reference")
    identity = record_id(row)
    name = "official-detail-"+gp+"-"+row["kind"]+"-"+identity+".html"
    path = captures/name
    if not path.is_file():
        return None, {"url":row["_detail_url"],"file":name}
    doc = html(path)
    fields=defaultdict(list)
    for table in doc.tables:
        for cells in table:
            for cell in cells:
                inline=re.fullmatch(r"(Voucher Date|Voucher No|Voucher Number|Amount\s*\(in\s*Rs\.?\)|Amount\s*\(in\s*Rupees\))\s*:\s*(.+)",cell["text"],re.I)
                if inline:
                    fields[re.sub(r"[^a-z]","",inline[1].lower())].append(inline[2].strip())
            for index,cell in enumerate(cells[:-1]):
                if ":" in cell["text"] and cell["text"].split(":",1)[1].strip():
                    continue
                key=re.sub(r"[^a-z]","",cell["text"].lower())
                if key in {"voucherdate","voucherno","vouchernumber","amountinrs","amountinrupees"}:
                    fields[key].append(cells[index+1]["text"])
    expected_date=datetime.strptime(row["date"],"%Y-%m-%d").strftime("%d/%m/%Y")
    if fields["voucherdate"]!=[expected_date] or fields["voucherno"]+fields["vouchernumber"]!=[row["voucher"]]:
        raise ValueError("Detailed voucher identity/date does not agree with monthly row")
    amounts=fields["amountinrs"]+fields["amountinrupees"]
    if len(amounts)!=1 or money(amounts[0])!=row["amount_paise"]:
        raise ValueError("Detailed voucher amount not confirmed")
    return {"url":row["_detail_url"],"sha256":digest(path),"voucher":row["voucher"],"date":row["date"],"amount_paise":row["amount_paise"]},None

def merge_vouchers(report, rows, captures):
    count=0;held=[];requests=[]
    counts=Counter((r["kind"],r["voucher"]) for r in rows)
    decisions={}
    for index,row in enumerate(rows):
        previous=[x for x in report.get(row["kind"],[]) if x["voucher"]==row["voucher"]]
        ambiguous=counts[(row["kind"],row["voucher"])]>1 or any(x["date"]!=row["date"] or x.get("amount_paise")!=row["amount_paise"] for x in previous)
        proof=None
        if ambiguous:
            try:
                proof,request=detail_proof(row,str(report["panchayat_code"]),captures)
                if request:
                    requests.append(request);decisions[index]=(False,None);continue
            except ValueError as error:
                held.append({"kind":row["kind"],"voucher":row["voucher"],"reason":str(error)});decisions[index]=(False,None);continue
        decisions[index]=(True,proof)
        for old in previous:
            if proof and not old.get("record_source") and old["date"]==row["date"] and old.get("amount_paise")==row["amount_paise"]:
                old["record_source"]=proof
                break
    for index,row in enumerate(rows):
        allowed,proof=decisions[index]
        if not allowed:continue
        kind=row["kind"];target=report.setdefault(kind,[])
        previous=[x for x in target if x["voucher"]==row["voucher"]]
        identical=[x for x in previous if x["date"]==row["date"] and x.get("amount_paise")==row["amount_paise"] and (not record_id(x) or not proof or record_id(x)==record_id({"record_source":proof}))]
        if identical:
            if len(identical)!=1:
                held.append({"kind":kind,"voucher":row["voucher"],"reason":"Multiple existing records with indistinguishable identity"});continue
            if proof and not identical[0].get("record_source"):
                identical[0]["record_source"]=proof
            continue
        if any(x["date"]==row["date"] and x.get("amount_paise")!=row["amount_paise"] and (not proof or not record_id(x) or record_id(x)==record_id({"record_source":proof})) for x in previous):
            held.append({"kind":kind,"voucher":row["voucher"],"reason":"Published voucher has a different amount; existing fact retained"});continue
        if previous and any(not x.get("record_source") for x in previous):
            held.append({"kind":kind,"voucher":row["voucher"],"reason":"Existing voucher identity needs corroboration before adding reused number"});continue
        if proof:
            row["record_source"]=proof
        target.append({k:v for k,v in row.items() if not k.startswith("_") and k!="kind"})
        count+=1
    return count,held,requests

def verified_plans(plans, civil, identities):
    gps,_=directory(civil)
    if len({p["gp_cd"] for p in plans})!=len(plans):
        raise ValueError("Duplicate GP plan")
    for p in plans:
        gp=gps.get(str(p["gp_cd"]))
        state=[r for r in identities["rows"] if r[0]=="PILIBHIT" and r[4]==str(p["gp_cd"])]
        if not gp or len(gp["names"])!=1 or gp["blocks"]!={p["parent_name"]} or len(state)!=1 or state[0][3]!=str(p["parent_cd"]):
            raise ValueError("Independent Panchayat/block verification failed")
        if p["zp_cd"]!=164 or p["parent_cd"] not in range(2021,2028) or p["plantype"]!="Main Plan":
            raise ValueError("Wrong plan geography/year tier")
    return gps

def build(captures, before, civil, identities, output, activities=True):
    original=json.loads(before.read_text())
    plans=json.loads((captures/"plans.json").read_text())
    gps=verified_plans(plans,civil,identities)
    by_gp={(r["panchayat_code"],r["financial_year"]):r for r in original["reports"]}
    output.mkdir(exist_ok=True)
    manifest={"schema":1,"publication_enabled":True,"captured_on":DAY,"reports":[]}
    review=[];detail_requests=[];totals=Counter()
    for plan in plans:
        code=str(plan["gp_cd"]);old=by_gp.get((code,YEAR));gp=gps[code]
        if old is None:
            raise ValueError("A verified baseline monthly report is required before importing details: "+code)
        report=copy.deepcopy(old)
        if set(report["mapping"]["village_lgd_codes"])!=gp["villages"]:
            raise ValueError("Published village membership differs from accepted directory: "+code)
        status={"gp":code,"name":report["name"],"block":plan["parent_name"],"held":[]}
        months,generated,requests=monthly(captures/("official-monthly-"+code+".html"),plan)
        rows=[]
        for request in requests:
            file=captures/("official-vouchers-"+code+"-"+request["month"]+".html")
            if not file.is_file():
                status["held"].append({"reason":"Voucher report unavailable","month":request["month"]});continue
            try:entries=voucher_entries(file,request)
            except (ValueError,KeyError) as error:
                status["held"].append({"reason":str(error),"month":request["month"]});continue
            key="vouchers_"+request["month"].replace("-","_")
            source={"url":request["url"],"report_date":entries[0]["_report_date"] if entries else DAY,"retrieved_on":DAY,"capture_sha256":digest(file)}
            previous=report["sources"].get(key)
            if previous and previous!=source:
                key+="_snapshot_"+digest(file)[:16]
            report["sources"][key]=source
            for entry in entries:entry["source_key"]=key
            rows.extend(entries)
        added,held,needed=merge_vouchers(report,rows,captures)
        status["held"].extend(held);detail_requests.extend(needed)
        status["new_vouchers"]=added;status["voucher_reports"]=len(requests)
        totals["new_vouchers"]+=added;totals["voucher_reports"]+=len(requests)
        if requests:totals["panchayats_with_voucher_reports"]+=1
        if activities:
            path=captures/("official-activities-"+code+"-"+str(plan["plan_cd"])+".json")
            labels=json.loads(path.read_text())
            if not isinstance(labels,list) or any(not isinstance(x,str) for x in labels):
                raise ValueError("Invalid official activity membership list")
            membership=[]
            for label in labels:
                match=re.fullmatch(r"(.*?)\s*\(([0-9]+)\)\s*",label)
                if not match:raise ValueError("Invalid activity membership ID")
                membership.append((label,match[2]))
            if len({x[1] for x in membership})!=len(membership):raise ValueError("Duplicate activity ID in plan")
            existing={a["code"]:a for a in report.get("activities",[])}
            verified=0;new=0
            for label,act in membership:
                detail=captures/("official-activity-"+act+".json")
                if not detail.is_file():
                    status["held"].append({"activity":act,"reason":"Activity detail unavailable"});continue
                try:row=activity(detail,label,plan)
                except (ValueError,KeyError,json.JSONDecodeError) as error:
                    status["held"].append({"activity":act,"reason":str(error)});continue
                verified+=1
                if act in existing and not existing[act].get("details") and "section_details" not in existing[act] and existing[act].get("source_evidence",{}).get("sha256")==row["source_evidence"]["sha256"]:
                    existing[act]["section_details"]=row["section_details"]
                if act not in existing:
                    report.setdefault("activities",[]).append(row);existing[act]=row;new+=1
            prior=report["sources"].get("activities")
            if prior:report.setdefault("previous_source_snapshots",{}).setdefault("activities",[]).append(prior)
            report["sources"]["activities"]={"url":BASE+"getActivityList.do?planCd="+str(plan["plan_cd"])+"&activityStts=0","report_date":DAY,"retrieved_on":DAY,"capture_sha256":digest(path),"report_date_basis":"Capture date; source provides no generation date"}
            status.update(activity_membership=len(membership),verified_activity_details=verified,new_activities=new)
            totals["new_activities"]+=new;totals["activity_membership"]+=len(membership);totals["verified_activity_details"]+=verified
            totals["panchayats_with_activity_lists"]+=1
        for kind in KINDS+["activities"]:
            for previous in old.get(kind,[]):
                if not any(same_facts(previous,current) for current in report.get(kind,[])):
                    raise ValueError("Previously published facts lost: "+code+" "+kind)
        file="9-173-"+code+"-"+YEAR+".json"
        dest=output/file
        dest.write_text(json.dumps({"schema":1,"report":report},ensure_ascii=False,separators=(",",":"))+"\n")
        if dest.stat().st_size>2*1024*1024:raise ValueError("Oversized GP shard")
        manifest["reports"].append({"panchayat_code":code,"financial_year":YEAR,"state_lgd":"9","district_lgd":"173","village_lgd_codes":report["mapping"]["village_lgd_codes"],"file":file,"sha256":digest(dest)})
        review.append(status)
    if activities and (captures/"supplementary-plans.json").is_file():
        main_by_gp={str(p["gp_cd"]):p for p in plans}
        reviews={r["gp"]:r for r in review}
        entries={r["panchayat_code"]:r for r in manifest["reports"]}
        for plan in json.loads((captures/"supplementary-plans.json").read_text()):
            code=str(plan["gp_cd"]);main=main_by_gp.get(code)
            if not main or plan["plantype"]!="Supplementary Plan" or any(plan[k]!=main[k] for k in ["zp_cd","parent_cd","parent_name","gp_cd"]):
                raise ValueError("Supplementary plan Panchayat/block does not match verified main plan")
            path=captures/("official-activities-"+code+"-"+str(plan["plan_cd"])+".json")
            labels=json.loads(path.read_text())
            if len(labels)!=len(set(labels)):raise ValueError("Repeated supplementary activity membership")
            entry=entries[code];file=output/entry["file"];data=json.loads(file.read_text());report=data["report"]
            known={a["code"] for a in report.get("activities",[])}
            added=0
            for label in labels:
                match=re.fullmatch(r"(.*?)\s*\(([0-9]+)\)\s*",label)
                if not match:raise ValueError("Invalid supplementary activity membership")
                row=activity(captures/("official-activity-"+match[2]+".json"),label,plan)
                row["source_evidence"]["plan_membership_sha256"]=digest(path)
                if row["code"] not in known:
                    report.setdefault("activities",[]).append(row);known.add(row["code"]);added+=1
            report.setdefault("activity_plan_sources",[]).append({"plan_id":str(plan["plan_cd"]),"type":plan["plantype"],"url":BASE+"getActivityList.do?planCd="+str(plan["plan_cd"])+"&activityStts=0","sha256":digest(path),"retrieved_on":DAY})
            file.write_text(json.dumps(data,ensure_ascii=False,separators=(",",":"))+"\n")
            entry["sha256"]=digest(file)
            for key in ["activity_membership","verified_activity_details","new_activities"]:
                totals[key]+=added;reviews[code][key]+=added
            totals["supplementary_plans"]+=1
    manifest["reports"].sort(key=lambda x:int(x["panchayat_code"]))
    eligible={str(p["gp_cd"]) for p in plans}
    manifest["unavailable"]=[{"panchayat_code":row[4],"name":row[2],"block":row[1],
        "reason":"No published 2026-2027 main plan in the captured official directory; current monthly report unavailable",
        "checked_on":DAY,"monthly_source_url":BASE+"FileRedirect.jsp?FD=FinancialYear2026-2027/9&name="+row[4]+".html",
        "capture_sha256":digest(captures/("official-monthly-"+row[4]+".html")) if (captures/("official-monthly-"+row[4]+".html")).is_file() else None}
        for row in identities["rows"] if row[0]=="PILIBHIT" and row[4] not in eligible]
    totals["unavailable_panchayats"]=len(manifest["unavailable"])
    (output/"manifest.json").write_text(json.dumps(manifest,ensure_ascii=False,separators=(",",":"))+"\n")
    (output/"coverage-review.json").write_text(json.dumps(review,ensure_ascii=False,indent=2)+"\n")
    unique={r["file"]:r for r in detail_requests}
    (output/"detail-downloads.json").write_text(json.dumps(list(unique.values()),indent=2))
    totals["pending_detailed_voucher_captures"]=len(unique);totals["held_rows"]=sum(len(x["held"]) for x in review)
    totals["panchayats"]=len(review)
    (output/"summary.json").write_text(json.dumps(dict(totals),indent=2))
    print(json.dumps(dict(totals)))

def main():
    p=argparse.ArgumentParser()
    for key in ["captures","before","civil","identities","output"]:p.add_argument("--"+key,type=Path,required=True)
    p.add_argument("--vouchers-only",action="store_true")
    a=p.parse_args()
    build(a.captures,a.before,a.civil,json.loads(a.identities.read_text()),a.output,not a.vouchers_only)
if __name__=="__main__":main()
