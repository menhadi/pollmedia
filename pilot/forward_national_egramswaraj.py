"""Forward completed public-source batches to the authoritative server."""
import argparse,io,json,os,subprocess,tarfile,time,zipfile,gzip
from pathlib import Path

def run(args,retries=3):
    for attempt in range(retries):
        result=subprocess.run(args,text=True,capture_output=True)
        if result.returncode==0:return result.stdout
        if attempt+1<retries:time.sleep(3)
    raise RuntimeError(result.stderr[-2000:])

def main():
    p=argparse.ArgumentParser();p.add_argument("--root",type=Path,required=True);p.add_argument("--identity",required=True);a=p.parse_args();root=a.root
    remote="/home/pollmedia/tmp/national-egs-summary-20261114";repo="/home/pollmedia/app";host="root@5.183.35.118"
    ssh=["ssh","-o","ConnectTimeout=10","-o","ServerAliveInterval=15","-o","ServerAliveCountMax=2","-i",a.identity,host]
    scp=["scp","-q","-o","ConnectTimeout=10","-i",a.identity]
    checkpoint=root/"forwarded.json";done=set(json.loads(checkpoint.read_text())) if checkpoint.exists() else set();catalog_done=(root/"catalog-forwarded").exists();part=0
    while True:
        prefixes=["national-topology-*.zip","national-plans-*.zip","national-monthly-*.zip","national-extra-monthly-*.zip","national-retry-*.zip"]
        files=sorted({f for pattern in prefixes for f in root.glob(pattern) if f.name not in done})
        primary_done=(root/"national-monthly-results.json").exists();extra_done=(root/"national-extra-monthly-results.json").exists()
        if files and (len(files)>=10 or primary_done or not catalog_done):
            part+=1;name="forward-"+str(time.time_ns())+".tar.xz";archive=root/name
            with tarfile.open(archive,"w:xz",preset=1) as tar:
                for file in files:
                    buf=io.BytesIO()
                    with zipfile.ZipFile(file) as source,zipfile.ZipFile(buf,"w",compression=zipfile.ZIP_STORED) as output:
                        for entry in source.infolist():output.writestr(entry.filename,source.read(entry.filename))
                    content=buf.getvalue();info=tarfile.TarInfo(file.name);info.size=len(content);tar.addfile(info,io.BytesIO(content))
            run(scp+[str(archive),host+":"+remote+"/"])
            extract="import tarfile,pathlib;p=pathlib.Path('"+remote+"');a=tarfile.open(p/'"+name+"');m=a.getmembers();assert all(x.isfile() and pathlib.Path(x.name).name==x.name for x in m);a.extractall(p,members=m)"
            run(ssh+["python3 -c "+repr(extract)])
            done.update(f.name for f in files);checkpoint.write_text(json.dumps(sorted(done)))
            print("Forwarded",len(files),"batches; total",len(done),flush=True)
        catalog_files={f.name for pattern in ["national-topology-*.zip","national-plans-*.zip"] for f in root.glob(pattern)}
        if not catalog_done and (root/"national-plans-results.json").exists() and (root/"national-plans-two-tier-results.json").exists() and catalog_files<=done:
            print(run(ssh+["cd "+repo+" && python3 pilot/national_egramswaraj_summaries.py catalog --work "+remote]),flush=True)
            run(scp+[host+":"+remote+"/extra-monthly-downloads.json",str(root/"extra-monthly-downloads.json")])
            requests=json.loads((root/"extra-monthly-downloads.json").read_text())
            if requests:
                log=open(root/"extra-collector.log","w")
                options={"creationflags":subprocess.CREATE_NO_WINDOW} if os.name=="nt" else {}
                subprocess.Popen(["pwsh","-NoProfile","-File",str(root/"acquire-archives.ps1"),"-Root",str(root),"-Manifest","extra-monthly-downloads.json","-Prefix","national-extra-monthly","-Concurrency","8"],stdout=log,stderr=subprocess.STDOUT,**options)
            else:(root/"national-extra-monthly-results.json").write_text("[]")
            (root/"catalog-forwarded").write_text("complete");catalog_done=True
        print(run(ssh+["cd "+repo+" && python3 pilot/national_egramswaraj_summaries.py ingest --work "+remote+" && if [ -f "+remote+"/publish-enabled ]; then python3 pilot/national_egramswaraj_summaries.py publish --work "+remote+" --destination application/database/fixtures/egramswaraj-national; fi"]),flush=True)
        all_files={f.name for pattern in prefixes for f in root.glob(pattern)}
        if primary_done and extra_done and all_files<=done:
            failed=[]
            for filename in ["national-monthly-results.json","national-extra-monthly-results.json"]:
                failed.extend(r for r in json.loads((root/filename).read_text(encoding="utf-8-sig")) if not r["ok"])
            body=gzip.compress(json.dumps(failed).encode());(root/"failed-requests.json.gz").write_bytes(body)
            run(scp+[str(root/"failed-requests.json.gz"),host+":"+remote+"/"])
            print(run(ssh+["cd "+repo+" && python3 pilot/national_egramswaraj_summaries.py failures --work "+remote+" && if [ -f "+remote+"/publish-enabled ]; then python3 pilot/national_egramswaraj_summaries.py publish --work "+remote+" --destination application/database/fixtures/egramswaraj-national; fi"]),flush=True)
            (root/"forward-complete.json").write_text(json.dumps({"failed_requests":len(failed),"batches":len(done)}))
            print("All completed summary batches forwarded",flush=True);break
        time.sleep(30)

if __name__=="__main__":main()
