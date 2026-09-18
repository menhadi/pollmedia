"""Export every collected Assembly edition with data notes and official provenance."""
import argparse,csv,hashlib,io,json,re,zipfile
from datetime import datetime,timezone
from pathlib import Path

FIELDS=['year','state_as_recorded','edition_id','election_round','archive_record_code','official_constituency_code','constituency','candidate','party_at_election','election_symbol','is_nota','general_votes','postal_votes','total_votes','reported_vote_percent','electors','votes_polled','valid_candidate_votes','status','data_note','official_source','source_document','source_sha256','detail_pdf_page','candidate_pdf_page','source_workbook_row','original_candidate_cells']


def safe(value):
    if isinstance(value,str) and re.match(r'^[\s]*[=+@-]|^[\t\r\n]',value):return "'"+value
    return value


def export(root,output):
    if output.exists():raise ValueError('Existing export preserved')
    entries=json.loads((root/'application/database/fixtures/eci-assembly-national.json').read_text(encoding='utf-8'))['entries']
    partial=output.with_suffix('.partial');snapshots=[];count=0;tables=0
    with zipfile.ZipFile(partial,'x',compression=zipfile.ZIP_DEFLATED,compresslevel=6) as archive:
        with archive.open('assembly-results.csv','w',force_zip64=True) as raw:
            with io.TextIOWrapper(raw,encoding='utf-8-sig',newline='') as stream:
                writer=csv.writer(stream);writer.writerow(FIELDS)
                for entry in entries:
                    edition=hashlib.sha256(entry['url'].encode()).hexdigest()[:24];folder=root/'application/storage/app/private/election-archive'/edition
                    payload=(folder/'extraction.json').read_bytes();data=json.loads(payload);manifest=json.loads((folder/'manifest.json').read_text(encoding='utf-8'))
                    if data['source_url']!=entry['url'] or data['year']!=entry['year']:raise ValueError('Extraction identity differs')
                    sources=[next(f for f in manifest['files'] if f['file']==data['source_file'])]+data.get('additional_sources',[])
                    for source in sources:
                        if Path(source['file']).name!=source['file'] or hashlib.sha256((folder/source['file']).read_bytes()).hexdigest()!=source['sha256']:raise ValueError('Source integrity failed')
                    snapshots.append(dict(edition_id=edition,source_url=entry['url'],extraction_sha256=hashlib.sha256(payload).hexdigest()))
                    for r in data['records']:
                        tables+=1
                        document=r.get('source_document',sources[0]['name']);source=next(f for f in sources if f['name']==document)
                        for c in r['candidates'] or [{}]:
                            values=[data['year'],r.get('state_name',entry['state']),edition,r.get('election_round'),r['code'],r.get('official_ac_code',r['code']),r.get('constituency_name',r['name']),c.get('candidate_name'),c.get('party_at_election'),c.get('election_symbol'),c.get('is_nota',False),c.get('general_votes'),c.get('postal_votes'),c.get('votes'),c.get('reported_vote_percent'),r.get('electors'),r.get('votes_polled'),r.get('valid_candidate_votes'),r['status'],r.get('error'),entry['url'],document,source['sha256'],r.get('detail_page'),c.get('source_page'),c.get('workbook_row'),json.dumps(c.get('source_values'),ensure_ascii=False) if 'source_values' in c else None]
                            writer.writerow([safe(v) for v in values]);count+=1
    with zipfile.ZipFile(partial) as archive:
        digest=hashlib.sha256()
        with archive.open('assembly-results.csv') as source:
            while block:=source.read(1024*1024):digest.update(block)
    metadata=dict(created_at=datetime.now(timezone.utc).isoformat(),editions=len(entries),constituency_tables=tables,candidate_and_nota_rows=count,csv_sha256=digest.hexdigest(),note='Source-reported values and extraction notes. Blank means not extracted or not reported, not zero. Constituency totals repeat on candidate rows; do not sum them across rows. OCR and source discrepancies require verification. Source documents are preserved separately.',snapshots=snapshots)
    with zipfile.ZipFile(partial,'a',compression=zipfile.ZIP_DEFLATED) as archive:archive.writestr('manifest.json',json.dumps(metadata,indent=2))
    with zipfile.ZipFile(partial) as archive:
        if archive.testzip() is not None:raise ValueError('ZIP integrity failed')
    partial.rename(output);digest=hashlib.sha256(output.read_bytes()).hexdigest();output.with_suffix('.sha256').write_bytes((digest+'  '+output.name+'\n').encode('ascii'));print(json.dumps({k:v for k,v in metadata.items() if k not in ['snapshots','note']}),flush=True)

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('--output',type=Path,required=True);a=p.parse_args();export(Path(__file__).resolve().parents[1],a.output.resolve())
