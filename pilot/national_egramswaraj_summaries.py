"""National first-pass monthly summaries. No voucher or activity-detail requests."""
import argparse,hashlib,json,os,re,sqlite3,unicodedata,zipfile,zlib,shutil
from collections import Counter
from datetime import datetime
from pathlib import Path
from zoneinfo import ZoneInfo
from expand_pilibhit_panchayat_reports import Tables

YEAR="2026-2027"
BASE="https://egramswaraj.gov.in/"
MONTHS={n:i for i,n in enumerate(["January","February","March","April","May","June","July","August","September","October","November","December"],1)}|{"Januaury":1}
def norm(s):return "".join(c for c in unicodedata.normalize("NFKC",str(s)).casefold().replace("&","and") if c.isalnum())
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
def day(stamp):return datetime.fromisoformat(re.sub(r"(\.\d{6})\d+",r"\1",stamp).replace("Z","+00:00")).astimezone(ZoneInfo("Asia/Kolkata")).date().isoformat()
def url(state,gp):return BASE+"FileRedirect.jsp?FD=FinancialYear"+YEAR+"/"+str(state)+"&name="+str(gp)+".html"
def database(root,state):
    root.mkdir(parents=True,exist_ok=True);c=sqlite3.connect(root/("state-"+str(state)+".sqlite"))
    c.execute("PRAGMA journal_mode=DELETE")
    c.execute("CREATE TABLE IF NOT EXISTS metadata(key TEXT PRIMARY KEY,value TEXT)")
    c.executemany("INSERT OR REPLACE INTO metadata VALUES(?,?)",[("schema","1"),("state",str(state)),("financial_year",YEAR),("encoding","zlib-json")])
    c.execute("CREATE TABLE IF NOT EXISTS panchayats(gp TEXT PRIMARY KEY,name TEXT NOT NULL,native_name TEXT,native_districts TEXT NOT NULL DEFAULT '{}',source_contexts TEXT NOT NULL DEFAULT '[]',status TEXT NOT NULL DEFAULT 'pending',identity_match INTEGER NOT NULL DEFAULT 0,report_blob BLOB,captured_on TEXT,captured_at TEXT,source_sha256 TEXT)")
    c.execute("CREATE INDEX IF NOT EXISTS panchayat_names ON panchayats(name COLLATE NOCASE)")
    c.execute("CREATE TABLE IF NOT EXISTS imports(name TEXT PRIMARY KEY,sha256 TEXT NOT NULL)")
    c.commit();return c

def prepare(work):
    registry=json.loads((work/"native-registry.json").read_text());root=work/"candidate";states={s["code"]:s["name"] for s in registry["states"]}
    by_state={}
    for row in registry["panchayats"]:by_state.setdefault(row["state"],[]).append(row)
    for state,rows in by_state.items():
        c=database(root,state)
        for r in rows:c.execute("INSERT INTO panchayats(gp,name,native_name,native_districts) VALUES(?,?,?,?) ON CONFLICT(gp) DO UPDATE SET native_name=excluded.native_name,native_districts=excluded.native_districts",(r["gp"],r["name"],r["name"],json.dumps(r["districts"],ensure_ascii=False)))
        c.commit();c.close()
    (work/"state-names.json").write_text(json.dumps(states,ensure_ascii=False));print("Prepared",len(registry["panchayats"]),"directory records",flush=True)

def catalog(work):
    names=json.loads((work/"state-names.json").read_text());root=work/"candidate";connections={};extra=[]
    def add(state,row):
        if not isinstance(row,dict) or not isinstance(row.get("gp_cd"),int) or row["gp_cd"]<=0 or not isinstance(row.get("gp_nam"),str):return
        if state not in names:return
        c=connections.setdefault(state,database(root,state)) if state not in connections else connections[state]
        gp=str(row["gp_cd"]);old=c.execute("SELECT source_contexts,native_name FROM panchayats WHERE gp=?",(gp,)).fetchone()
        context={k:row.get(k) for k in ["zp_cd","zp_name","parent_cd","parent_name","local_body_typ_cd","gp_nam","source_url","source_sha256","captured_on"]}
        contexts=json.loads(old[0]) if old else []
        if context not in contexts:contexts.append(context)
        c.execute("INSERT INTO panchayats(gp,name,source_contexts) VALUES(?,?,?) ON CONFLICT(gp) DO UPDATE SET source_contexts=excluded.source_contexts",(gp,row["gp_nam"],json.dumps(contexts,ensure_ascii=False)))
    for archive in sorted(work.glob("national-topology-*.zip")):
        with zipfile.ZipFile(archive) as z:
            meta={x["file"]:x for f in z.namelist() if f.startswith("_capture-") for x in [json.loads(line) for line in z.read(f).decode("utf-8-sig").splitlines()]}
            for filename in z.namelist():
                m=re.fullmatch(r"official-topology-([0-9]+)\.json",filename)
                if m:
                    assert meta[filename]["url"]==BASE+"getPlanZpData.do?stateCd="+m[1]+"&planYr=2026&level=3"
                    raw=z.read(filename);rows=json.loads(raw.decode("utf-8-sig"))
                    if isinstance(rows,list):
                        for row in rows:
                            row.update(source_url=meta[filename]["url"],source_sha256=hashlib.sha256(raw).hexdigest(),captured_on=day(meta[filename]["captured_at"]));add(m[1],row)
    for archive in sorted(work.glob("national-plans-*.zip")):
        with zipfile.ZipFile(archive) as z:
            meta={x["file"]:x for f in z.namelist() if f.startswith("_capture-") for x in [json.loads(line) for line in z.read(f).decode("utf-8-sig").splitlines()]}
            for filename in z.namelist():
                m=re.fullmatch(r"official-plans-([0-9]+)-([0-9]+)-([0-9]+)\.json",filename)
                if not m:continue
                assert meta[filename]["url"]==BASE+"getPlanVpData.do?planYr=2026&stateCd="+m[1]+"&zpCd="+m[2]+"&bpCd="+m[3]+"&typCd=3&level=3"
                raw=z.read(filename);rows=json.loads(raw.decode("utf-8-sig"))
                if not isinstance(rows,list):continue
                for row in rows:
                    if str(row.get("zp_cd"))!=m[2] or (str(row.get("parent_cd"))!=m[3] and not (m[3]=="0" and row.get("parent_cd") in [None,0])):continue
                    row.update(source_url=meta[filename]["url"],source_sha256=hashlib.sha256(raw).hexdigest(),captured_on=day(meta[filename]["captured_at"]));add(m[1],row)
    for state,c in connections.items():c.commit();c.close()
    for path in sorted(root.glob("state-*.sqlite")):
        state=path.stem.split("-")[1];c=sqlite3.connect(path)
        for gp, in c.execute("SELECT gp FROM panchayats WHERE native_name IS NULL"):extra.append({"file":"official-monthly-"+state+"-"+gp+".html","url":url(state,gp)})
        c.close()
    (work/"extra-monthly-downloads.json").write_text(json.dumps(extra,separators=(",",":")));print("Additional official identities",len(extra),flush=True)

def parse(body,state,gp,captured,native_name,state_name,native_districts):
    text=body.decode("utf-8-sig",errors="replace");doc=Tables();doc.feed(text);doc.close();doc.body=" ".join(" ".join(doc.text).split())
    report={"name":native_name or gp,"financial_year":YEAR,"source_url":url(state,gp),"captured_on":captured,"report_date":None,"monthly":[],"notes":[],"source_state":None,"source_district":None,"identity_match":False}
    if "File does not exist" in text:
        report.update(status="unavailable");report["notes"].append("The official source has no monthly report available for this identifier and financial year.");return report
    if "Month wise Summary Report" not in doc.body:
        report.update(status="format_note");report["notes"].append("The source response could not be read as a monthly summary. Open the official source for its original response.");return report
    parsed_name=False
    patterns={"source_state":r"State\s*[:-]+\s*(.+?)(?=,?\s*District\s*[:-])","source_district":r"District\s*[:-]+\s*(.+?)(?=,?\s*(?:Village|Gram|Block|District|Local)\s+(?:Panchayat|Council|Body))","name":r"(?:Village\s+Panchayat|Gram\s+Panchayat|Village\s+Council|Local\s+Body)\s*(?:&\s*Equivalent)?\s*[:-]+\s*(.+?)(?=\s+Month\s+Receipts)"}
    for key,pattern in patterns.items():
        match=re.search(pattern,doc.body,re.I)
        if match:
            report[key]=match[1].strip(" ,")
            if key=="name":parsed_name=True
    match=re.search(r"Financial\s+Year\s*[:-]+\s*(20[0-9]{2}\s*-\s*20[0-9]{2})",doc.body,re.I)
    if match:
        report["financial_year"]=re.sub(r"\s","",match[1])
        if report["financial_year"]!=YEAR:report["notes"].append("The source returned a different financial year; its original year is shown.")
    for pattern,formats in [(r"on [A-Za-z]+, ([A-Za-z]+ [0-9]+, [0-9]{4})",["%B %d, %Y"]),(r"on [A-Za-z]+, ([0-9]{1,2} [A-Za-z]+ [0-9]{4})",["%d %b %Y","%d %B %Y"])]:
        match=re.search(pattern,doc.body)
        if match:
            for fmt in formats:
                try:report["report_date"]=datetime.strptime(match[1],fmt).date().isoformat();break
                except ValueError:pass
        if report["report_date"]:break
    if not report["report_date"]:report["notes"].append("The source provides no readable generation date; the retrieval date is shown separately.")
    elif report["report_date"]>captured:report["notes"].append("The source generation date is later than the retrieval date; both dates are preserved.")
    for table in doc.tables:
        for row in table:
            if len(row)!=5:continue
            cells=[c["text"] for c in row];m=re.fullmatch(r"([A-Za-z]+), ([0-9]{4})",cells[0])
            if not m or m[1] not in MONTHS:continue
            month=m[2]+"-"+str(MONTHS[m[1]]).zfill(2);values=[int(v.replace(",","")) if re.fullmatch(r"[0-9]+(?:,[0-9]+)*",v) else (v or None) for v in cells[1:]]
            if report["report_date"] and month>report["report_date"][:7] and all(v==0 for v in values):continue
            item=dict(month=month,**dict(zip(["receipts","payments","contra","journal"],values)))
            if report["report_date"] and month>report["report_date"][:7]:item["note"]="Later than the source report month."
            report["monthly"].append(item)
    if not report["monthly"]:report["notes"].append("No readable monthly rows were returned; the source response remains available through its reference link.")
    expected_state=norm(state_name);actual_state=norm(report["source_state"] or "")
    report["identity_match"]=bool(parsed_name and native_name and norm(report["name"])==norm(native_name) and expected_state==actual_state)
    if native_name and not report["identity_match"]:report["notes"].append("Source and directory names differ or could not be confirmed. The source record is published as reported; village correspondence remains pending.")
    if report["source_district"] and native_districts and norm(report["source_district"]) not in {norm(v) for v in native_districts.values()}:report["notes"].append("The source district name differs from the current directory; original district names are retained.")
    report["status"]="available" if report["monthly"] and report["financial_year"]==YEAR else "format_note";return report

def ingest(work):
    root=work/"candidate";names=json.loads((work/"state-names.json").read_text());connections={};changed=set();processed=0
    ledger=sqlite3.connect(work/"ingest-ledger.sqlite");ledger.execute("CREATE TABLE IF NOT EXISTS archives(name TEXT PRIMARY KEY,sha256 TEXT)")
    done={r[0]:r[1] for r in ledger.execute("SELECT name,sha256 FROM archives")}
    for archive in sorted([*work.glob("national-monthly-*.zip"),*work.glob("national-extra-monthly-*.zip"),*work.glob("national-retry-*.zip")]):
        if archive.name in done:continue
        digest=sha(archive)
        with zipfile.ZipFile(archive) as z:
            indexes=[n for n in z.namelist() if n.startswith("_capture-") and n.endswith(".jsonl")];assert len(indexes)==1
            entries=[json.loads(line) for line in z.read(indexes[0]).decode("utf-8-sig").splitlines()]
            for entry in entries:
                m=re.fullmatch(r"official-monthly-([0-9]+)-([0-9]+)\.html",entry["file"]);assert m
                state,gp=m.groups();assert entry["url"]==url(state,gp)
                if state not in names:continue
                c=connections.setdefault(state,database(root,state)) if state not in connections else connections[state]
                row=c.execute("SELECT native_name,native_districts,captured_at FROM panchayats WHERE gp=?",(gp,)).fetchone()
                if row is None:raise ValueError("Monthly capture has no registered source identity: "+state+"/"+gp)
                if row[2] and row[2]>entry["captured_at"]:continue
                body=z.read(entry["file"]);report=parse(body,state,gp,day(entry["captured_at"]),row[0],names[state],json.loads(row[1]));report["source_sha256"]=hashlib.sha256(body).hexdigest()
                blob=zlib.compress(json.dumps(report,ensure_ascii=False,separators=(",",":")).encode(),6)
                c.execute("UPDATE panchayats SET status=?,identity_match=?,report_blob=?,captured_on=?,captured_at=?,source_sha256=? WHERE gp=?",(report["status"],int(report["identity_match"]),blob,report["captured_on"],entry["captured_at"],report["source_sha256"],gp));changed.add(state);processed+=1
        for c in connections.values():c.commit()
        ledger.execute("INSERT OR REPLACE INTO archives VALUES(?,?)",(archive.name,digest));ledger.commit()
    for c in connections.values():c.close()
    ledger.close();print(json.dumps({"ingested":processed,"changed_states":sorted(changed)}),flush=True)
    return changed

def seed_existing(work,fixtures):
    names=json.loads((work/"state-names.json").read_text());reports=[]
    base=fixtures/"egramswaraj-accounts.json"
    if base.exists():reports.extend(json.loads(base.read_text()).get("reports",[]))
    for path in (fixtures/"egramswaraj-details").rglob("*.json"):
        if re.fullmatch(r"[0-9]+-[0-9]+-[0-9]+-2026-2027\.json",path.name):reports.append(json.loads(path.read_text())["report"])
    connections={};count=0
    for old in reports:
        state=str(old.get("mapping",{}).get("state_lgd",""));gp=str(old.get("panchayat_code",""));source=old.get("sources",{}).get("monthly",{})
        if old.get("financial_year")!=YEAR or old.get("mapping",{}).get("verified") is not True or not old.get("months") or state not in names:continue
        if source.get("url")!=url(state,gp) or not re.fullmatch(r"[a-f0-9]{64}",source.get("capture_sha256","")) or not re.fullmatch(r"20[0-9]{2}-[0-9]{2}-[0-9]{2}",source.get("retrieved_on","")):continue
        c=connections.setdefault(state,database(work/"candidate",state)) if state not in connections else connections[state]
        row=c.execute("SELECT native_name,native_districts,status FROM panchayats WHERE gp=?",(gp,)).fetchone()
        if not row or row[2]!="pending":continue
        districts=json.loads(row[1]);district=districts.get(str(old["mapping"].get("district_lgd")))
        report={"name":old["name"],"financial_year":YEAR,"source_url":source["url"],"captured_on":source["retrieved_on"],"report_date":source["report_date"],"monthly":old["months"],"notes":[],"source_state":names[state],"source_district":district,"identity_match":bool(row[0] and norm(row[0])==norm(old["name"])),"status":"available","source_sha256":source["capture_sha256"],"source_basis":"Previously published source snapshot; original source hash and dates retained"}
        c.execute("UPDATE panchayats SET status='available',identity_match=?,report_blob=?,captured_on=?,captured_at=?,source_sha256=? WHERE gp=?",(int(report["identity_match"]),zlib.compress(json.dumps(report,ensure_ascii=False,separators=(",",":")).encode(),6),source["retrieved_on"],source["retrieved_on"],source["capture_sha256"],gp));count+=1
    for c in connections.values():c.commit();c.close()
    print("Reused existing monthly source snapshots",count,flush=True)

def repair_names(work):
    names=json.loads((work/"state-names.json").read_text());count=0
    for path in sorted((work/"candidate").glob("state-*.sqlite")):
        state=path.stem.split("-")[1];c=sqlite3.connect(path,timeout=60)
        rows=c.execute("SELECT gp,native_name,report_blob FROM panchayats WHERE report_blob IS NOT NULL").fetchall()
        for gp,native,blob in rows:
            report=json.loads(zlib.decompress(blob));match=re.search(r"(?:Village\s+Panchayat|Gram\s+Panchayat|Village\s+Council)\s*(?:&\s*Equivalent)?\s*[:-]+\s*(.+)$",report["name"],re.I)
            if not match:continue
            report["name"]=match[1].strip(" ,")
            confirmed=bool(native and norm(report["name"])==norm(native) and norm(report.get("source_state") or "")==norm(names[state]))
            report["identity_match"]=confirmed
            if confirmed:report["notes"]=[n for n in report["notes"] if not n.startswith("Source and directory names differ")]
            c.execute("UPDATE panchayats SET identity_match=?,report_blob=? WHERE gp=?",(int(confirmed),zlib.compress(json.dumps(report,ensure_ascii=False,separators=(",",":")).encode(),6),gp));count+=1
        c.commit();c.close()
    print("Corrected header field parsing",count,flush=True)

def failures(work):
    import gzip
    path=work/"failed-requests.json.gz"
    if not path.exists():return
    rows=json.loads(gzip.decompress(path.read_bytes()));names=json.loads((work/"state-names.json").read_text());connections={};count=0
    for row in rows:
        m=re.fullmatch(r"official-monthly-([0-9]+)-([0-9]+)\.html",row["file"])
        if not m:continue
        state,gp=m.groups()
        if state not in names:continue
        c=connections.setdefault(state,database(work/"candidate",state)) if state not in connections else connections[state]
        old=c.execute("SELECT name,status FROM panchayats WHERE gp=?",(gp,)).fetchone()
        if not old or old[1]!="pending":continue
        captured=datetime.now(ZoneInfo("Asia/Kolkata")).date().isoformat()
        report={"name":old[0],"financial_year":YEAR,"source_url":url(state,gp),"captured_on":captured,"report_date":None,"monthly":[],"notes":["The official monthly report could not be retrieved in this pass. Its source link is retained for checking later."],"source_state":None,"source_district":None,"identity_match":False,"status":"fetch_failed"}
        blob=zlib.compress(json.dumps(report,ensure_ascii=False,separators=(",",":")).encode(),6)
        c.execute("UPDATE panchayats SET status='fetch_failed',report_blob=?,captured_on=? WHERE gp=?",(blob,captured,gp));count+=1
    for c in connections.values():c.commit();c.close()
    print("Published retrieval notes",count,flush=True)

def publish(work,destination):
    names=json.loads((work/"state-names.json").read_text());destination.mkdir(parents=True,exist_ok=True)
    previous=json.loads((destination/"index.json").read_text()) if (destination/"index.json").exists() else {"states":{}}
    index={"schema":1,"publication_enabled":True,"financial_year":YEAR,"states":{}}
    for state,name in names.items():
        path=work/"candidate"/("state-"+state+".sqlite")
        if not path.exists():
            index["states"][state]={"name":name,"total":0,"counts":{},"note":"No records returned by the current source catalog."};continue
        signature=str(path.stat().st_mtime_ns)+":"+str(path.stat().st_size);target=destination/path.name;old=previous.get("states",{}).get(state,{})
        if target.exists() and old.get("candidate_signature")==signature and old.get("sha256")==sha(target):
            index["states"][state]=old;continue
        temp=destination/("."+path.name+".tmp")
        if temp.exists():temp.unlink()
        source=sqlite3.connect(path);snapshot=sqlite3.connect(temp)
        source.backup(snapshot);source.close()
        counts=dict(snapshot.execute("SELECT status,count(*) FROM panchayats GROUP BY status"));total=sum(counts.values());latest=snapshot.execute("SELECT max(captured_on) FROM panchayats").fetchone()[0];snapshot.close()
        if temp.stat().st_size>95*1024*1024:raise ValueError("State snapshot requires partitioning before publication: "+state)
        digest=sha(temp);size=temp.stat().st_size;os.chown(temp,1034,1022);os.chmod(temp,0o644);os.replace(temp,target)
        index["states"][state]={"name":name,"file":path.name,"sha256":digest,"bytes":size,"total":total,"counts":counts,"captured_on":latest,"candidate_signature":signature}
    temp=destination/".index.json.tmp";temp.write_text(json.dumps(index,ensure_ascii=False,separators=(",",":"))+"\n");os.chown(temp,1034,1022);os.chmod(temp,0o644);os.replace(temp,destination/"index.json")
    print(json.dumps({"published_records":sum(v["total"] for v in index["states"].values()),"counts":dict(sum((Counter(v["counts"]) for v in index["states"].values()),Counter()))}),flush=True)

if __name__=="__main__":
    p=argparse.ArgumentParser();p.add_argument("action",choices=["prepare","catalog","ingest","failures","repair-names","seed-existing","publish"]);p.add_argument("--work",type=Path,required=True);p.add_argument("--destination",type=Path);p.add_argument("--fixtures",type=Path);a=p.parse_args()
    if a.action=="prepare":prepare(a.work)
    elif a.action=="catalog":catalog(a.work)
    elif a.action=="ingest":ingest(a.work)
    elif a.action=="failures":failures(a.work)
    elif a.action=="repair-names":repair_names(a.work)
    elif a.action=="seed-existing":seed_existing(a.work,a.fixtures)
    else:publish(a.work,a.destination)
