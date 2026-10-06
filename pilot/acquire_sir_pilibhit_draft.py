"""Acquire one PDF from the official Pilibhit draft-roll ZIP using validated ranges."""
import argparse,hashlib,io,json,re,zipfile
from pathlib import Path
import requests
from bs4 import BeautifulSoup
LANDING='https://pilibhit.nic.in/meeting-blo-bla/'
FILE_ID='14MYTjeyq4cEFetIEEhKN-_lMnuQCwY5q'
ARCHIVE=f'https://drive.google.com/file/d/{FILE_ID}/view'

class RemoteZip(io.RawIOBase):
    def __init__(self,session,url,size):self.session=session;self.url=url;self.size=size;self.position=0
    def seekable(self):return True
    def tell(self):return self.position
    def seek(self,offset,whence=0):
        target=offset if whence==0 else self.position+offset if whence==1 else self.size+offset
        if not 0<=target<=self.size:raise ValueError('Invalid archive seek')
        self.position=target;return target
    def read(self,size=-1):
        size=min(self.size-self.position,size if size>=0 else self.size-self.position)
        if size<=0:return b''
        if size>100000000:raise ValueError('Range exceeds 100 MB cap')
        end=self.position+size-1
        response=self.session.get(self.url,headers={'Range':f'bytes={self.position}-{end}'},timeout=90)
        response.raise_for_status()
        if response.status_code!=206 or response.headers.get('Content-Range')!=f'bytes {self.position}-{end}/{self.size}' or len(response.content)!=size:raise ValueError('Server did not return the requested byte range')
        self.position+=size;return response.content

if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('--part',type=int,default=1);parser.add_argument('--output-dir',required=True);args=parser.parse_args()
    if not 1<=args.part<=419:raise ValueError('Part outside pilot inventory')
    session=requests.Session();landing=session.get(LANDING,timeout=30);landing.raise_for_status()
    if FILE_ID not in landing.text:raise ValueError('Official archive link changed; review the source')
    warning=session.get('https://drive.google.com/uc',params={'export':'download','id':FILE_ID},timeout=30);warning.raise_for_status()
    form=BeautifulSoup(warning.text,'html.parser').find('form')
    if form is None or form.get('action')!='https://drive.usercontent.google.com/download':raise ValueError('Unexpected public download response')
    params={i['name']:i.get('value','') for i in form.find_all('input') if i.get('name')}
    probe=session.get(form['action'],params=params,headers={'Range':'bytes=-65536'},timeout=60,stream=True)
    if probe.status_code!=206:raise ValueError('Public archive does not support range access')
    match=re.fullmatch(r'bytes [0-9]+-[0-9]+/([0-9]+)',probe.headers.get('Content-Range',''))
    if not match:raise ValueError('Missing archive size')
    reader=RemoteZip(session,probe.url,int(match[1]));probe.close()
    with zipfile.ZipFile(reader) as archive:
        members=[i for i in archive.infolist() if i.filename.endswith(f'S24-127-SIR-DraftRoll-Revision1-HIN-{args.part}-WI.pdf')]
        if len(members)!=1 or members[0].file_size>100000000 or members[0].flag_bits&1:raise ValueError('Unexpected part PDF inventory')
        data=archive.read(members[0]);member=members[0].filename
    if not data.startswith(b'%PDF-'):raise ValueError('Part is not a PDF')
    output=Path(args.output_dir);output.mkdir(parents=True,exist_ok=True);pdf=output/f'part-{args.part:03}.pdf';pdf.write_bytes(data)
    metadata=dict(source_url=ARCHIVE,source_landing_url=LANDING,zip_member=member,pdf_sha256=hashlib.sha256(data).hexdigest(),bytes=len(data))
    (output/f'part-{args.part:03}.source.json').write_text(json.dumps(metadata),encoding='utf-8');print(json.dumps(metadata))
