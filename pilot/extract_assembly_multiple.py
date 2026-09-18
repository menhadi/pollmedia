"""Keep multiple official election reports in one catalogue edition distinct."""
import argparse,hashlib,json
from datetime import datetime,timezone
from pathlib import Path
from extract_assembly_2000s import extract


def run(entry,root):
    folder=root/hashlib.sha256(entry['url'].encode()).hexdigest()[:24];output=folder/'extraction.json'
    if output.exists():raise ValueError('Existing extraction is preserved')
    manifest=json.loads((folder/'manifest.json').read_text(encoding='utf-8'))
    if manifest['url']!=entry['url'] or manifest['year']!=entry['year']:raise ValueError('Manifest identity differs')
    files=sorted(manifest['files'],key=lambda f:f['name'])
    if len(files)<2 or any(not f['file'].endswith('.pdf') for f in files):raise ValueError('Expected separate combined PDF reports')
    records=[]
    for index,source in enumerate(files,1):
        path=folder/source['file']
        if Path(source['file']).name!=source['file'] or hashlib.sha256(path.read_bytes()).hexdigest()!=source['sha256']:raise ValueError('Source integrity failed')
        rows=extract(path,entry['state'])
        for record in rows:
            if not 0<record['code']<100000:raise ValueError('Unexpected official code')
            record['official_ac_code']=record['code'];record['code']=index*100000+record['code']
            record['election_round']=Path(source['name']).stem
            record['name']+=' / '+record['election_round']
            record['source_document']=source['name']
            record['source_locator']=source['name']+'; official constituency code '+str(record['official_ac_code'])+'. Separate election reports are retained independently.'
            records.append(record)
    data=dict(kind='ac',year=entry['year'],source_url=entry['url'],source_file=files[0]['file'],source_sha256=files[0]['sha256'],additional_sources=files[1:],extracted_at=datetime.now(timezone.utc).isoformat(),records=records)
    temp=folder/'extraction.tmp';temp.write_text(json.dumps(data,indent=2,ensure_ascii=False),encoding='utf-8');temp.replace(output)
    print(json.dumps(dict(state=entry['state'],year=entry['year'],reports=len(files),tables=len(records),rows=sum(len(r['candidates']) for r in records))))

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('catalogue',type=Path);p.add_argument('root',type=Path);p.add_argument('--state',required=True);p.add_argument('--year',type=int,required=True);a=p.parse_args()
    entries=[e for e in json.loads(a.catalogue.read_text(encoding='utf-8'))['entries'] if e['state']==a.state and e['year']==a.year]
    if len(entries)!=1:raise ValueError('Expected one exact catalogue entry')
    run(entries[0],a.root)
