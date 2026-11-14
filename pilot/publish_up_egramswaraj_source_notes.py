"""Publish official source availability and dated exceptions without guessing village links."""
import argparse,copy,json,re
from pathlib import Path
from expand_up_egramswaraj import capture_index,source_capture
from expand_pilibhit_panchayat_reports import BASE,YEAR,digest,html,voucher_entries

def build(fixtures,captures,output):
    root=fixtures/"egramswaraj-details";index=json.loads((root/"up-districts.json").read_text());metadata=capture_index(captures)
    held=json.loads((captures/"identity-held.json").read_text());topology=json.loads((captures/"official-up-zp-plans.json").read_text())
    output.mkdir(parents=True,exist_ok=True)
    for district,name in [("130","Bareilly"),("180","Shahjahanpur")]:
        source=root/("9-"+district);target=output/("9-"+district);target.mkdir(exist_ok=True)
        manifest=json.loads((source/"manifest.json").read_text());assert digest(source/"manifest.json")==index["districts"][district]["sha256"]
        entries={r["panchayat_code"]:r for r in manifest["reports"]}
        def save(report,source_only=False):
            gp=report["panchayat_code"];file="9-"+district+"-"+gp+"-"+YEAR+".json";old=source/file
            if old.exists():
                previous=json.loads(old.read_text())["report"]
                for key in ["months","payments","receipts","contra","journal","activities"]:assert report.get(key)==previous.get(key),"Existing source rows changed"
            path=target/file;path.write_text(json.dumps({"schema":1,"report":report},ensure_ascii=False,separators=(",",":"))+"\n")
            entry=copy.deepcopy(entries.get(gp,{"panchayat_code":gp,"financial_year":YEAR,"state_lgd":"9","district_lgd":district,"village_lgd_codes":[]}));entry.update(file=file,sha256=digest(path))
            if source_only:entry.update(source_only=True,name=report["name"],note=report["source_notes"][0])
            entries[gp]=entry
        identities=html(captures/("official-up-"+name.upper()+".html"))
        for item in [x for x in held if x["district_lgd"]==district]:
            gp=item["gp"];rows=[r for t in identities.tables for r in t if len(r)==18 and r[17]["text"]==gp and r[1]["text"]==name.upper()];assert len(rows)==1
            row=rows[0];assert any(str(b["parent_cd"])==row[16]["text"] and b["zp_name"]==name for b in topology)
            url=BASE+"FileRedirect.jsp?FD=FinancialYear2026-2027/9&name="+gp+".html";path,day=source_capture(captures,metadata,"official-monthly-"+gp+".html",url)
            assert "File does not exist" in path.read_text(),"A newly available report needs importing, not an unavailable label"
            note="Village linkage pending. The official source has no current-year financial report or activity plan."
            report={"name":row[3]["text"],"panchayat_code":gp,"financial_year":YEAR,"block_name":row[2]["text"]+" · "+name+" · Uttar Pradesh",
                "mapping":{"verified":True,"source_only":True,"state_lgd":"9","district_lgd":district,"village_lgd_codes":[],"village_evidence":{},
                    "evidence":{"method":"Official UP Panchayati Raj GP identity and block/district hierarchy; village membership not established","state_identity_sha256":digest(captures/("official-up-"+name.upper()+".html")),"egram_block":row[16]["text"]}},
                "source_notes":[note],"sources":{"availability":{"url":url,"report_date":day,"retrieved_on":day,"capture_sha256":digest(path),"report_date_basis":"Capture of official missing-file response"}}}
            save(report,True)
        for gp in (["53188"] if district=="130" else ["86968","87299","87572"]):
            report=json.loads((source/entries[gp]["file"]).read_text())["report"];notes=report.setdefault("source_notes",[])
            if district=="130":
                url=next(x["url"] for x in json.loads((captures/"voucher-requests.json").read_text()) if x["gp"]==gp and x["month"]=="2026-11")
                path,day=source_capture(captures,metadata,"official-vouchers-"+gp+"-2026-11.html",url)
                rows=voucher_entries(path,{"gp":gp,"month":"2026-11","captured_on":day},allow_reported_date_discrepancy=True)
                assert any(r["date"]>r["_report_date"] for r in rows)
                report["source_reported_vouchers"]=[{**{k:v for k,v in r.items() if not k.startswith("_")},"source_note":"report-date-discrepancy"} for r in rows]
                report["sources"]["vouchers_2026_11"]={"url":url,"report_date":rows[0]["_report_date"],"retrieved_on":day,"capture_sha256":digest(path)}
                note="The source report contains a date discrepancy. All its entries are shown separately with original dates and amounts; they are not added to verified receipt/payment totals."
            else:
                url=BASE+"FileRedirect.jsp?FD=FinancialYear2026-2027/9&name="+gp+".html";path,day=source_capture(captures,metadata,"official-monthly-"+gp+".html",url)
                assert "File does not exist" in path.read_text()
                report["sources"]["availability"]={"url":url,"report_date":day,"retrieved_on":day,"capture_sha256":digest(path),"report_date_basis":"Capture of official missing-file response"}
                note="The official monthly financial report is unavailable. Available activity records are shown below."
            if note not in notes:notes.append(note)
            save(report)
        manifest["reports"]=sorted(entries.values(),key=lambda r:int(r["panchayat_code"]));(target/"manifest.json").write_text(json.dumps(manifest,ensure_ascii=False,separators=(",",":"))+"\n")
        summary=json.loads((source/"summary.json").read_text());summary.update(published_panchayats=len(entries),source_only_panchayats=sum(bool(e.get("source_only")) for e in entries.values()),source_noted_components=max(summary.get("source_noted_components",0),summary["held_components"]),held_components=0)
        summary["source_reported_voucher_entries"]=len(report.get("source_reported_vouchers",[])) if district=="130" else 0
        (target/"summary.json").write_text(json.dumps(summary,indent=2)+"\n")
        for file in ["identity-held.json","coverage-review.json"]:
            rows=json.loads((source/file).read_text())
            for item in rows:
                if file=="identity-held.json" or item.get("held"):item["publication_status"]="published-with-source-note"
            (target/file).write_text(json.dumps(rows,ensure_ascii=False,indent=2)+"\n")
        index["districts"][district]["sha256"]=digest(target/"manifest.json")
    (output/"up-districts.json").write_text(json.dumps(index,ensure_ascii=False,indent=2)+"\n")

if __name__=="__main__":
    p=argparse.ArgumentParser()
    for name in ["fixtures","captures","output"]:p.add_argument("--"+name,type=Path,required=True)
    a=p.parse_args();build(a.fixtures,a.captures,a.output)
