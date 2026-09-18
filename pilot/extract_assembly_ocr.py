"""Parse preserved OCR coordinates; every published record retains an OCR warning."""
import argparse,hashlib,json
from pathlib import Path
from types import SimpleNamespace
import fitz
from extract_assembly_symbols import extract

class OcrPage:
    def __init__(self,data):
        self.data=data;self.rect=SimpleNamespace(width=data['width'],height=data['height'])
    def get_text(self,mode=None):
        if mode!='words':return self.data['text']
        words=[tuple(w[:5]) for w in self.data['words']]
        if not any(w[4]=='SYMBOL' and w[1]<170 for w in words):
            general=next((w for w in words if w[4]=='GENERAL' and w[1]<170),None)
            if general:words.append((*general[:4],'SYMBOL'))
        return words
    def search_for(self,label):
        rects=[]
        for w in self.get_text('words'):
            start=w[4].find(label)
            if start>=0:
                scale=(w[2]-w[0])/len(w[4]);rects.append(fitz.Rect(w[0]+start*scale,w[1],w[0]+(start+len(label))*scale,w[3]))
        return rects

class OcrDocument(list):
    is_ocr=True
    def __enter__(self):return self
    def __exit__(self,*args):pass


def run(folder,state,refresh=False):
    output=folder/'extraction.json'
    previous=output.read_bytes() if output.exists() else None
    if previous and (not refresh or not all(r.get('extraction_method')=='ocr' for r in json.loads(previous)['records'])):raise ValueError('Existing extraction preserved')
    manifest=json.loads((folder/'manifest.json').read_text(encoding='utf-8'));source=manifest['files'][0];path=folder/source['file']
    ocr_path=folder/'ocr-words-v1.json';ocr=json.loads(ocr_path.read_text(encoding='utf-8'))
    if hashlib.sha256(path.read_bytes()).hexdigest()!=source['sha256'] or ocr['source_sha256']!=source['sha256']:raise ValueError('Source checksum differs')
    pages={p['page']:p for p in ocr['pages']}
    document=OcrDocument([OcrPage(pages.get(i,dict(width=612,height=842,text='',words=[]))) for i in range(1,max(pages)+1)])
    records=extract(path,state,document)
    for record in records:
        record['error']='OCR transcription of a scanned official report; text and numbers require source verification. '+record['error']
        record['extraction_method']='ocr'
    data=dict(kind='ac',year=manifest['year'],source_url=manifest['url'],source_file=source['file'],source_sha256=source['sha256'],ocr_file=ocr_path.name,ocr_sha256=hashlib.sha256(ocr_path.read_bytes()).hexdigest(),records=records)
    if previous:(folder/('extraction-'+hashlib.sha256(previous).hexdigest()+'.json')).write_bytes(previous)
    temp=folder/'extraction.tmp';temp.write_text(json.dumps(data,indent=2,ensure_ascii=False),encoding='utf-8');temp.replace(output)
    print(state,len(records),sum(len(r['candidates']) for r in records))

if __name__=='__main__':
    p=argparse.ArgumentParser();p.add_argument('folder',type=Path);p.add_argument('--state',required=True);a=p.parse_args();run(a.folder,a.state)
