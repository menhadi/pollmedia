"""Verify and publish eGramSwaraj snapshots against accepted UP GP/village directories."""
import argparse
import hashlib
import copy
import json
import re
from collections import Counter, defaultdict
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo
from urllib.parse import urlencode
from expand_pilibhit_panchayat_reports import BASE, YEAR, activity, digest, html, monthly, norm, voucher_entries
from build_pilibhit_detail_shards import merge_vouchers, same_facts, record_id

def verified_roster(captures,fixtures):
    civil_manifest=json.loads((fixtures/"up-panchayat-directories.json").read_text())
    if civil_manifest.get("schema")!=1 or civil_manifest.get("publication_enabled") is not True:
        raise ValueError("Accepted civil publication is not enabled")
    topology=json.loads((captures/"official-up-zp-plans.json").read_text())
    verified=[];held=[]
    for district in json.loads((captures/"districts.json").read_text()):
        code=district["lgd"]
        if not re.fullmatch(r"[1-9][0-9]{0,5}",code):raise ValueError("Invalid civil district code")
        file=fixtures/("up-panchayat-directory-9-"+code+".json")
        raw=file.read_bytes();civil_hash=hashlib.sha256(raw).hexdigest()
        if civil_manifest["districts"][code]["file"]!=file.name or civil_hash!=civil_manifest["districts"][code]["sha256"]:
            raise ValueError("Accepted directory hash/file mismatch")
        civil=json.loads(raw)
        if any(v["district_code"]!=code or v["district_name"]!=district["name"] for v in civil["villages"]):
            raise ValueError("Mixed civil district rows")
        groups={}
        for village in civil["villages"]:
            if len(village.get("panchayats",[]))!=1:continue
            gp=village["panchayats"][0]
            group=groups.setdefault(gp["code"],{"names":set(),"villages":set(),"blocks":set(),"block_names":set()})
            group["names"].add(gp["name"]);group["villages"].add(village["code"])
            for block in village.get("blocks",[]):
                group["blocks"].add(block["code"]);group["block_names"].add(block["name"])
        state_file=captures/("official-up-"+district["name"].upper()+".html")
        doc=html(state_file);state_hash=digest(state_file)
        identities=[{"name":row[3]["text"],"gp":row[17]["text"],"block":row[2]["text"],"block_egs":row[16]["text"]}
                    for table in doc.tables for row in table if len(row)==18 and norm(row[1]["text"])==norm(district["name"])]
        if not identities:raise ValueError("No independently captured state Panchayat identities")
        by_gp=defaultdict(list)
        for row in identities:by_gp[row["gp"]].append(row)
        blocks={str(b["parent_cd"]):b for b in topology if b["zp_name"]==district["name"]}
        plan_rows=[]
        for block in blocks.values():
            plan_rows.extend(json.loads((captures/("official-plans-"+str(block["parent_cd"])+".json")).read_text()))
        forward=defaultdict(set);reverse=defaultdict(set)
        for row in identities:
            group=groups.get(row["gp"])
            if group and len(group["blocks"])==1 and row["block_egs"] in blocks:
                forward[row["block_egs"]].update(group["blocks"])
        for parent,children in forward.items():
            for child in children:reverse[child].add(parent)
        for row in identities:
            gp=row["gp"];group=groups.get(gp);block=blocks.get(row["block_egs"]);reason=None
            if not re.fullmatch(r"[1-9][0-9]{4,6}",gp) or len(by_gp[gp])!=1:
                reason="Missing or conflicting state Panchayat identity"
            elif not group or len(group["names"])!=1 or not group["villages"]:
                reason="Missing or ambiguous accepted village membership"
            elif not block or len(group["blocks"])!=1 or len(group["block_names"])!=1 or forward[row["block_egs"]]!=group["blocks"] or any(len(reverse[b])!=1 for b in group["blocks"]):
                reason="Missing or conflicting block namespace correspondence"
            plans=[v for v in plan_rows if str(v["gp_cd"])==gp]
            if not reason and any(v["zp_cd"]!=block["zp_cd"] or str(v["parent_cd"])!=row["block_egs"] or v["plantype"] not in ["Main Plan","Supplementary Plan"] for v in plans):
                reason="Official plan hierarchy conflicts with the independently verified Panchayat"
            if reason:
                held.append({"district_lgd":code,"district":district["name"],"gp":gp,"name":row["name"],"reason":reason});continue
            verified.append({"district_lgd":code,"district_name":district["name"],"gp":gp,"name":next(iter(group["names"])),
                "state_name":row["name"],"block_lgd":next(iter(group["blocks"])),"block_name":next(iter(group["block_names"])),
                "block_egs":int(row["block_egs"]),"zp_cd":block["zp_cd"],"zp_name":block["zp_name"],"plans":plans,
                "villages":sorted(group["villages"]),"civil_file":file.name,"civil_sha256":civil_hash,"state_identity_sha256":state_hash})
    (captures/"identity-held.json").write_text(json.dumps(held,ensure_ascii=False,indent=2)+"\n")
    return verified

def capture_index(root):
    result={}
    for path in root.glob("_capture-*.jsonl"):
        for line in path.read_text(encoding="utf-8-sig").splitlines():
            data=json.loads(line)
            stamp=re.sub(r"(\.\d{6})\d+",r"\1",data["captured_at"]).replace("Z","+00:00")
            data["day"]=datetime.fromisoformat(stamp).astimezone(ZoneInfo("Asia/Kolkata")).date().isoformat()
            previous=result.get(data["file"])
            if previous and previous["url"]!=data["url"]:
                raise ValueError("Capture filename has conflicting official URLs")
            if previous is None or data["captured_at"]>previous["captured_at"]:
                result[data["file"]]=data
    return result

def source_capture(root,index,name,url):
    path=root/name;meta=index.get(name)
    if not path.is_file() or not meta:
        raise ValueError("Official capture unavailable: "+name)
    if meta["url"]!=url:
        raise ValueError("Capture URL differs from verified request")
    return path,meta["day"]

def monthly_context(gp,day):
    plan=next((p.copy() for p in gp["plans"] if p["plantype"]=="Main Plan"), {"gp_cd":int(gp["gp"]),"gp_nam":gp["state_name"],"zp_cd":gp["zp_cd"],"zp_name":gp["zp_name"],"parent_cd":gp["block_egs"]})
    plan.update(captured_on=day,gp_aliases=[gp["name"],gp["state_name"]])
    return plan

def initial_report(gp):
    return {"name":gp["name"],"panchayat_code":gp["gp"],"financial_year":YEAR,
            "block_name":gp["block_name"]+" · "+gp["district_name"]+" · Uttar Pradesh","sources":{},
            "mapping":{"verified":True,"state_lgd":"9","district_lgd":gp["district_lgd"],"village_lgd_codes":gp["villages"],
                "evidence":{"method":"Explicit GP IDs in official eGram plan hierarchy, UP Panchayati Raj identity rows and accepted LGD directory; one-to-one block namespace correspondence",
                    "civil_sha256":gp["civil_sha256"],"state_identity_sha256":gp["state_identity_sha256"],
                    "civil_source_url":"https://lgdirectory.gov.in/downloadDirectory.do",
                    "state_identity_url":"https://panchayatiraj.up.nic.in/pblc_pg/Reports/PB2FormReport?"+urlencode({"District":gp["district_name"].upper(),"ReportType":"Filled"}),
                    "plan_directory_url":BASE+"getPlanVpData.do?"+urlencode({"planYr":2026,"stateCd":9,"zpCd":gp["zp_cd"],"bpCd":gp["block_egs"],"typCd":3,"level":3}),
                    "egram_zp":str(gp["zp_cd"]),"egram_block":str(gp["block_egs"]),"lgd_block":gp["block_lgd"]},
                "village_evidence":{"method":"Existing accepted unambiguous LGD GP-to-village membership","civil_sha256":gp["civil_sha256"]}}}

def district_build(captures,output,district,roster,index,existing,with_activities):
    output.mkdir(parents=True,exist_ok=True)
    entries=[];reviews=[];detail_requests=[];totals=Counter();all_days=[]
    for gp in roster:
        code=gp["gp"];old=existing.get((code,YEAR));report=copy.deepcopy(old) if old else initial_report(gp)
        if report["mapping"]["district_lgd"]!=district or set(report["mapping"]["village_lgd_codes"])!=set(gp["villages"]):
            raise ValueError("Existing geography differs from verified current membership")
        status={"gp":code,"name":gp["name"],"district_lgd":district,"district":gp["district_name"],"block":gp["block_name"],"held":[]}
        rows=[];financial_date=None
        try:
            url=BASE+"FileRedirect.jsp?FD=FinancialYear2026-2027/9&name="+code+".html"
            path,day=source_capture(captures,index,"official-monthly-"+code+".html",url)
            months,generated,requests=monthly(path,monthly_context(gp,day))
            if report.get("months") and report["months"]!=months:
                report.setdefault("previous_monthly_snapshots",[]).append({"months":report["months"],"source":report["sources"].get("monthly")})
            report["months"]=months;report["sources"]["monthly"]={"url":url,"report_date":generated,"retrieved_on":day,"capture_sha256":digest(path),"report_date_basis":"Official generation date"}
            financial_date=day;totals["monthly_panchayats"]+=1;status["monthly_status"]="verified"
            for req in requests:
                try:
                    file="official-vouchers-"+code+"-"+req["month"]+".html"
                    doc,checked=source_capture(captures,index,file,req["url"])
                    req["captured_on"]=checked
                    values=voucher_entries(doc,req)
                    key="vouchers_"+req["month"].replace("-","_")
                    source={"url":req["url"],"report_date":values[0]["_report_date"] if values else checked,"retrieved_on":checked,"capture_sha256":digest(doc)}
                    if report["sources"].get(key) and report["sources"][key]!=source:key+="_snapshot_"+digest(doc)[:16]
                    report["sources"][key]=source
                    for value in values:value["source_key"]=key
                    rows.extend(values);all_days.append(checked);totals["voucher_reports"]+=1
                except (ValueError,KeyError,json.JSONDecodeError) as error:
                    status["held"].append({"component":"vouchers","month":req["month"],"reason":str(error)})
        except (ValueError,KeyError,json.JSONDecodeError) as error:
            status["monthly_status"]="unavailable";status["held"].append({"component":"monthly","reason":str(error)})
        for row in rows:
            if row.get("_detail_url"):
                file="official-detail-"+code+"-"+row["kind"]+"-"+record_id(row)+".html"
                if (captures/file).is_file():
                    source_capture(captures,index,file,row["_detail_url"])
        count,held,needed=merge_vouchers(report,rows,captures)
        status["new_vouchers"]=count;totals["new_vouchers"]+=count;status["held"].extend(held);detail_requests.extend(needed)
        if financial_date:all_days.append(financial_date)
        if with_activities:
            known={a["code"]:a for a in report.get("activities",[])}
            membership=set();verified=set();days=[];plan_sources=[];new_count=0
            for plan in gp["plans"]:
                try:
                    if str(plan["gp_cd"])!=code or plan["zp_cd"]!=gp["zp_cd"] or plan["parent_cd"]!=gp["block_egs"]:
                        raise ValueError("Plan GP/block/district identity mismatch")
                    name="official-activities-"+code+"-"+str(plan["plan_cd"])+".json"
                    url=BASE+"getActivityList.do?planCd="+str(plan["plan_cd"])+"&activityStts=0"
                    path,day=source_capture(captures,index,name,url)
                    labels=json.loads(path.read_text(encoding="utf-8-sig"))
                    if not isinstance(labels,list) or any(not isinstance(v,str) for v in labels) or len(labels)!=len(set(labels)):
                        raise ValueError("Invalid or duplicated plan membership labels")
                    plan_sources.append({"plan_id":str(plan["plan_cd"]),"type":plan["plantype"],"url":url,"sha256":digest(path),"retrieved_on":day})
                    days.append(day)
                    for label in labels:
                        match=re.fullmatch(r"(.*?)\s*\(([0-9]+)\)\s*",label)
                        if not match:raise ValueError("Unrecognised official activity label")
                        act=match[2];membership.add(act)
                        try:
                            doc,checked=source_capture(captures,index,"official-activity-"+act+".json",BASE+"activitySectionData.do?activityCd="+act)
                            context=plan.copy();context["captured_on"]=checked
                            row=activity(doc,label,context);row["source_evidence"]["plan_membership_sha256"]=digest(path)
                            days.append(checked);verified.add(act)
                            if act not in known:
                                report.setdefault("activities",[]).append(row);known[act]=row;new_count+=1
                        except (ValueError,KeyError,json.JSONDecodeError) as error:
                            status["held"].append({"component":"activity","activity":act,"reason":str(error)})
                except (ValueError,KeyError,json.JSONDecodeError) as error:
                    status["held"].append({"component":"plan","plan_id":str(plan["plan_cd"]),"reason":str(error)})
            if plan_sources:
                report["activity_plan_sources"]=plan_sources
                first=plan_sources[0]
                report["sources"]["activities"]={"url":first["url"],"report_date":max(days),"retrieved_on":first["retrieved_on"],
                    "capture_sha256":first["sha256"],"report_date_basis":"Latest individual capture; each activity retains its own date"}
                all_days.extend(days)
            status.update(activity_membership=len(membership),verified_activity_details=len(verified),new_activities=new_count,activity_plans=len(plan_sources))
            totals["activity_membership"]+=len(membership);totals["verified_activity_details"]+=len(verified);totals["new_activities"]+=new_count;totals["activity_plans"]+=len(plan_sources)
        if old:
            for key in ["payments","receipts","contra","journal","activities"]:
                for previous in old.get(key,[]):
                    if not any(same_facts(previous,current) for current in report.get(key,[])):
                        raise ValueError("Previously published fact would be lost")
        if report["sources"]:
            file="9-"+district+"-"+code+"-"+YEAR+".json"
            dest=output/file;dest.write_text(json.dumps({"schema":1,"report":report},ensure_ascii=False,separators=(",",":"))+"\n")
            if dest.stat().st_size>2*1024*1024:raise ValueError("GP shard exceeds safe request limit")
            entries.append({"panchayat_code":code,"financial_year":YEAR,"state_lgd":"9","district_lgd":district,"village_lgd_codes":report["mapping"]["village_lgd_codes"],"file":file,"sha256":digest(dest)})
        reviews.append(status)
    manifest={"schema":1,"publication_enabled":True,"scope":{"state":"9","district":district},"captured_on":max(all_days) if all_days else None,"reports":sorted(entries,key=lambda r:int(r["panchayat_code"]))}
    (output/"manifest.json").write_text(json.dumps(manifest,ensure_ascii=False,separators=(",",":"))+"\n")
    (output/"coverage-review.json").write_text(json.dumps(reviews,ensure_ascii=False,indent=2)+"\n")
    totals["published_panchayats"]=len(entries);totals["identity_verified"]=len(roster);totals["held_components"]=sum(len(r["held"]) for r in reviews)
    (output/"summary.json").write_text(json.dumps(dict(totals),indent=2)+"\n")
    return dict(totals),detail_requests

def build(captures,output,fixtures,with_activities=True):
    roster=verified_roster(captures,fixtures)
    index=capture_index(captures)
    civil_manifest=json.loads((fixtures/"up-panchayat-directories.json").read_text())
    if civil_manifest.get("schema")!=1 or civil_manifest.get("publication_enabled") is not True:raise ValueError("Civil publication not approved")
    for code in {g["district_lgd"] for g in roster}:
        file=fixtures/("up-panchayat-directory-9-"+code+".json")
        civil_hash=digest(file)
        if civil_hash!=civil_manifest["districts"][code]["sha256"]:raise ValueError("Accepted civil directory hash mismatch")
        for gp in [r for r in roster if r["district_lgd"]==code]:
            if gp["civil_sha256"]!=civil_hash:raise ValueError("Roster civil evidence changed")
    package=json.loads((fixtures/"egramswaraj-accounts.json").read_text())
    existing={(r["panchayat_code"],r["financial_year"]):r for r in package["reports"]}
    published_index=fixtures/"egramswaraj-details/up-districts.json"
    if published_index.is_file():
        published=json.loads(published_index.read_text())
        if published.get("schema")!=1 or published.get("publication_enabled") is not True:
            raise ValueError("Existing district index is invalid; preserving it")
        for district in {g["district_lgd"] for g in roster}:
            entry=published.get("districts",{}).get(district)
            if not entry:continue
            expected="9-"+district+"/manifest.json";path=published_index.parent/expected
            if entry["file"]!=expected or digest(path)!=entry["sha256"]:
                raise ValueError("Existing published district manifest integrity mismatch")
            current=json.loads(path.read_text())
            for source in current["reports"]:
                file=path.parent/source["file"]
                if source["file"]!="9-"+district+"-"+source["panchayat_code"]+"-"+source["financial_year"]+".json" or digest(file)!=source["sha256"]:
                    raise ValueError("Existing published GP file integrity mismatch")
                report=json.loads(file.read_text())["report"]
                existing[(report["panchayat_code"],report["financial_year"])]=report
    output.mkdir(exist_ok=True)
    summaries={};needed=[]
    for code in sorted({g["district_lgd"] for g in roster}):
        summaries[code],details=district_build(captures,output/("9-"+code),code,[g for g in roster if g["district_lgd"]==code],index,existing,with_activities)
        needed.extend(details)
        held=[r for r in json.loads((captures/"identity-held.json").read_text()) if r["district_lgd"]==code]
        (output/("9-"+code)/"identity-held.json").write_text(json.dumps(held,ensure_ascii=False,indent=2)+"\n")
    requests={r["file"]:r for r in needed}
    (output/"detail-downloads.json").write_text(json.dumps(list(requests.values())))
    (output/"summary.json").write_text(json.dumps(summaries,indent=2)+"\n")
    district_index={"schema":1,"publication_enabled":True,"districts":{}}
    if published_index.is_file():
        district_index=copy.deepcopy(published)
    for code in summaries:
        path=output/("9-"+code)/"manifest.json"
        district_index["districts"][code]={"file":"9-"+code+"/manifest.json","sha256":digest(path)}
    (output/"up-districts.json").write_text(json.dumps(district_index,ensure_ascii=False,indent=2)+"\n")
    (output/"identity-held.json").write_text((captures/"identity-held.json").read_text())
    print(json.dumps({"districts":summaries,"needed_detailed_vouchers":len(requests)}))

def main():
    p=argparse.ArgumentParser()
    for name in ["captures","output","fixtures"]:p.add_argument("--"+name,type=Path,required=True)
    p.add_argument("--vouchers-only",action="store_true")
    a=p.parse_args();build(a.captures,a.output,a.fixtures,not a.vouchers_only)
if __name__=="__main__":main()
