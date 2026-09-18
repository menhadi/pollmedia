"""Preserve OCR words and confidence alongside untouched official image-only PDFs."""
import argparse,csv,hashlib,io,json,os,subprocess,tempfile
from concurrent.futures import ThreadPoolExecutor,as_completed
from pathlib import Path
import fitz


def ocr_page(source,index,cache):
    checkpoint=cache/f'{index:04}.json'
    if checkpoint.exists():return json.loads(checkpoint.read_text(encoding='utf-8'))
    with fitz.open(source) as doc:
        page=doc[index];width,height=page.rect.width,page.rect.height
        pix=page.get_pixmap(matrix=fitz.Matrix(250/72,250/72),colorspace=fitz.csGRAY)
    png=cache/f'{index:04}.png';pix.save(png)
    result=subprocess.run(['tesseract',str(png),'stdout','--psm','3','tsv'],capture_output=True,check=True,env=dict(os.environ,OMP_THREAD_LIMIT='1'))
    words=[];lines={}
    for row in csv.DictReader(io.StringIO(result.stdout.decode('utf-8')),delimiter='\t',quoting=csv.QUOTE_NONE):
        text=row.get('text','').strip()
        if not text:continue
        x,y,w,h=[int(row[k])*72/250 for k in ['left','top','width','height']]
        words.append([x,y,x+w,y+h,text,float(row['conf'])])
        key=tuple(row[k] for k in ['block_num','par_num','line_num']);lines.setdefault(key,[]).append(text)
    data=dict(page=index+1,width=width,height=height,text='\n'.join(' '.join(v) for v in lines.values()),words=words)
    checkpoint.write_text(json.dumps(data,ensure_ascii=False),encoding='utf-8');png.unlink();return data


def run(source,output,start=0):
    digest=hashlib.sha256(source.read_bytes()).hexdigest();cache=Path('pilot/tmp')/('ocr-'+digest[:16]);cache.mkdir(parents=True,exist_ok=True)
    with fitz.open(source) as doc:count=len(doc)
    pages=[]
    with ThreadPoolExecutor(max_workers=3) as executor:
        jobs=[executor.submit(ocr_page,source,i,cache) for i in range(start,count)]
        for job in as_completed(jobs):
            pages.append(job.result())
            if len(pages)%10==0:print(f'OCR {len(pages)}/{count-start}',flush=True)
    data=dict(source_sha256=digest,method='Tesseract 250dpi psm3; machine transcription, not independently verified',pages=sorted(pages,key=lambda p:p['page']))
    output.write_text(json.dumps(data,ensure_ascii=False),encoding='utf-8');print(str(output),flush=True)

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('source',type=Path);parser.add_argument('output',type=Path);a=parser.parse_args();run(a.source,a.output)
