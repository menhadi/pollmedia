"""Recover only missing candidate arrays, leaving existing populated tables untouched."""
import hashlib,json
from pathlib import Path
from extract_assembly_legacy import extract as legacy
from extract_assembly_components import extract as components
from extract_assembly_2000s import extract as dot
from extract_assembly_flat import extract as flat
from extract_assembly_modern import normal,count


def repair(entry,root):
    folder=root/hashlib.sha256(entry['url'].encode()).hexdigest()[:24];path=folder/'extraction.json';old=path.read_bytes();data=json.loads(old)
    missing=[r for r in data['records'] if not r['candidates']]
    if not missing:return 0
    source=folder/data['source_file']
    if source.name!=data['source_file'] or hashlib.sha256(source.read_bytes()).hexdigest()!=data['source_sha256']:raise ValueError('Source checksum differs')
    for additional in data.get('additional_sources',[]):
        if Path(additional['file']).name!=additional['file'] or hashlib.sha256((folder/additional['file']).read_bytes()).hexdigest()!=additional['sha256']:raise ValueError('Additional source checksum differs')
    changed=0
    for record in missing:
        summary=record.get('summary_source_rows',[])
        winners=[(i,row) for i,row in enumerate(summary,1) if len(row)>5 and str(row[1]).lower()=='winner']
        if len(winners)==1 and any('uncontested' in str(row).lower() for row in summary):
            index,winner=winners[0]
            if isinstance(winner[3],str) and isinstance(winner[4],str) and winner[3].strip() and winner[4].strip():
                record['candidates']=[dict(candidate_name=winner[4],party_at_election=winner[3],votes=count(winner[5]),general_votes=None,postal_votes=None,reported_contest_status='uncontested',source_summary_row=index,source_values=winner)]
                record['source_locator']='Candidate identity from the official constituency summary; detailed candidate rows are absent.'
                record['error']='The official summary reports an uncontested winner. Candidate identity and displayed vote cell are preserved from that summary; general and postal vote components were not reported.'
                changed+=1

    for adapter in ([flat] if source.suffix in ['.xlsx','.xls'] else [legacy,components,dot]):
        try:rows=adapter(source,entry['state'])
        except ValueError:continue
        mapped={r['code']:r for r in rows}
        for record in missing:
            replacement=mapped.get(record['code'])
            if record['candidates'] or not replacement or not replacement['candidates'] or normal(record['name'])!=normal(replacement['name']):continue
            record['candidates']=replacement['candidates'];record['status']='needs_review';record['error']=replacement['error']+' Candidate rows recovered from the same archived source; earlier extraction note: '+record.get('error','')
            changed+=1
    if changed:
        (folder/('extraction-'+hashlib.sha256(old).hexdigest()+'.json')).write_bytes(old)
        temp=folder/'extraction.tmp';temp.write_text(json.dumps(data,indent=2,ensure_ascii=False),encoding='utf-8');temp.replace(path)
    return changed

if __name__=='__main__':
    catalogue=Path('application/database/fixtures/eci-assembly-national.json');root=Path('application/storage/app/private/election-archive')
    for entry in json.loads(catalogue.read_text(encoding='utf-8'))['entries']:
        n=repair(entry,root)
        if n:print(entry['state'],entry['year'],n,flush=True)
