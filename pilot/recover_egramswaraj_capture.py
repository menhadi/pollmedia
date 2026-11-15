"""Recover CRC-verified complete entries without changing the interrupted capture.

URLs come only from the original request manifest. Retrieval precision is one
confirmed local calendar day; exact per-entry times are not reconstructed.
"""
import argparse
import hashlib
import json
import re
import struct
import zipfile
import zlib
from datetime import datetime,timezone
from pathlib import Path
from urllib.parse import urlparse
from zoneinfo import ZoneInfo

def recover(part,manifest,output):
    if output.exists():raise ValueError('Recovery output already exists')
    match=re.fullmatch(r'capture-(?:financial|activities)-([0-9]{8}T[0-9]{12})-[0-9]+\.part',part.name)
    if not match:raise ValueError('Unrecognised capture window')
    start=datetime.strptime(match[1],'%Y%m%dT%H%M%S%f').replace(tzinfo=timezone.utc)
    end=datetime.fromtimestamp(part.stat().st_mtime,timezone.utc)
    if end<start or start.astimezone(ZoneInfo('Asia/Kolkata')).date()!=end.astimezone(ZoneInfo('Asia/Kolkata')).date():raise ValueError('Retrieval day is not unambiguous')
    expected={}
    for row in json.loads(manifest.read_text(encoding='utf-8-sig')):
        if row['file'] in expected and expected[row['file']]!=row['url']:raise ValueError('Manifest has conflicting URLs')
        u=urlparse(row['url'])
        if u.scheme!='https' or u.netloc!='egramswaraj.gov.in':raise ValueError('Non-official manifest URL')
        expected[row['file']]=row['url']
    data=part.read_bytes();offset=0;entries=[];seen=set()
    while offset+30<=len(data) and data[offset:offset+4]==b'PK\x03\x04':
        values=struct.unpack_from('<IHHHHHIIIHH',data,offset);flags,method,crc,size,original,nlen,xlen=values[2],values[3],values[6],values[7],values[8],values[9],values[10]
        name=data[offset+30:offset+30+nlen].decode('utf-8');begin=offset+30+nlen+xlen;finish=begin+size
        if flags&1 or flags&8 or method not in [0,8] or original>64*1024**2:raise ValueError('Unsupported or oversized ZIP entry')
        if not size or finish>len(data):break
        if name not in expected or not re.fullmatch(r'detail-[a-f0-9]{64}\.(?:html|json)',name) or name in seen:raise ValueError('Entry is not uniquely bound to the original request manifest')
        if method==8:
            decoder=zlib.decompressobj(-15);body=decoder.decompress(data[begin:finish],original+1)
            if decoder.unconsumed_tail or not decoder.eof:break
        else:body=data[begin:finish]
        if len(body)!=original or zlib.crc32(body)&0xffffffff!=crc:break
        entries.append((name,body));seen.add(name);offset=finish
    if not entries:raise ValueError('No complete CRC-verified entries')
    notes={'original_part':part.name,'original_sha256':hashlib.sha256(data).hexdigest(),'complete_entries':len(entries),'unrecovered_bytes':len(data)-offset,'capture_window_start':start.isoformat(),'capture_window_end':end.isoformat()}
    metadata=[]
    with zipfile.ZipFile(output,'x',compression=zipfile.ZIP_DEFLATED,compresslevel=1) as target:
        for name,body in entries:
            target.writestr(name,body);metadata.append({'file':name,'url':expected[name],'captured_at':end.isoformat(),'captured_at_precision':'day','capture_window_start':start.isoformat(),'capture_window_end':end.isoformat(),'recovery_original_sha256':notes['original_sha256']})
        target.writestr('_capture-recovered.jsonl',''.join(json.dumps(row)+'\n' for row in metadata));target.writestr('_recovery.json',json.dumps(notes))
    return notes

def main():
    p=argparse.ArgumentParser();p.add_argument('--part',type=Path,required=True);p.add_argument('--manifest',type=Path,required=True);p.add_argument('--output',type=Path,required=True);a=p.parse_args();print(json.dumps(recover(a.part,a.manifest,a.output)))
if __name__=='__main__':main()
