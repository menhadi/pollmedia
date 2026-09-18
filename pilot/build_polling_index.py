"""Publish an inventory of preserved polling-source documents and completed extractions."""
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
from urllib.parse import unquote, urlparse
from polling_manifest import load_manifest


def build(root):
    catalogue=json.loads((root/'catalogue.json').read_text(encoding='utf-8'))
    sources=[];states=[]
    for entry in catalogue['entries']:
        folder=root/entry['id'];path=folder/'manifest.json'
        if not path.exists():
            states.append(entry | {'status':'discovery_pending','documents':0,'errors':[],'pending_pages':0})
            continue
        manifest=load_manifest(path);seen=set()
        for document in manifest['documents']:
            if not document.get('file') or document['file'] in seen:
                continue
            seen.add(document['file'])
            index_path=folder/(document['sha256']+'-tables')/'index.json'
            extraction=json.loads(index_path.read_text(encoding='utf-8')) if index_path.exists() else None
            ocr_path=folder/(document['sha256']+'-ocr')/'index.json'
            ocr=json.loads(ocr_path.read_text(encoding='utf-8')) if ocr_path.exists() else {'pages':[]}
            if extraction:
                by_page={p['page']:p for p in ocr['pages']}
                for page in extraction['pages']:
                    if page['page'] in by_page:
                        page['ocr']=by_page[page['page']]
            page_manifest = None
            if extraction:
                page_body = json.dumps({'pages': extraction['pages']}, ensure_ascii=False).encode('utf-8')
                page_digest = hashlib.sha256(page_body).hexdigest()
                page_file = document['sha256']+'-pages-'+page_digest[:16]+'.json'
                if not (folder/page_file).exists():
                    page_temporary = folder/(page_file+'.tmp')
                    page_temporary.write_bytes(page_body)
                    page_temporary.replace(folder/page_file)
                page_manifest = {'file': page_file, 'sha256': page_digest}
            sources.append({'id':hashlib.sha256((entry['id']+document['sha256']).encode()).hexdigest()[:24],
                            'state':entry['state'],'folder':entry['id'],'file':document['file'],'sha256':document['sha256'],
                            'name':document.get('label') or unquote(Path(urlparse(document['url']).path).name),
                            'source_url':document['url'],'discovered_on':document.get('discovered_on',entry['url']),
                            'pages':[], 'page_count':len(extraction['pages']) if extraction else 0, 'page_manifest':page_manifest,
                            'polling_rows':extraction['polling_rows'] if extraction else 0,
                            'status':'extracted_with_notes' if extraction else 'awaiting_extraction'})
        states.append(entry | {'status':'discovery_incomplete','documents':len(seen),'errors':manifest['errors'],
                              'failed_documents':sum(d.get('status')=='download_failed' for d in manifest['documents']),
                              'pending_pages':len(manifest['pending_pages'])})
    data={'built_at':datetime.now(timezone.utc).isoformat(),'states':states,'sources':sources,
          'scope_note':'Official source discovery is incomplete. Documents can overlap, and not every state/year or polling station is represented. Counts are extracted source rows, not unique polling stations. Blank, unreadable, postal and aggregate rows are retained in the original tables.'}
    temporary=root/'index.tmp';temporary.write_text(json.dumps(data,ensure_ascii=False),encoding='utf-8');temporary.replace(root/'index.json')
    print(json.dumps({'state_directory_entries':len(states),'documents':len(sources),'extracted_documents':sum(bool(s['page_count']) for s in sources),
                      'polling_rows':sum(s['polling_rows'] for s in sources),'pending_pages':sum(s['pending_pages'] for s in states)}))


if __name__=='__main__':
    build(Path(__file__).resolve().parents[1]/'application/storage/app/private/polling-station-sources')
