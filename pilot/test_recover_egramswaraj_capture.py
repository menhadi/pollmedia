import hashlib
import json
import os
from pathlib import Path
import struct
import tempfile
import unittest
import zipfile
from datetime import datetime,timezone
from recover_egramswaraj_capture import recover

class CaptureRecoveryTest(unittest.TestCase):
    def setUp(self):
        self.temp=tempfile.TemporaryDirectory();self.root=Path(self.temp.name);self.part=self.root/'capture-financial-20261115T010203000000-0001.part';self.output=self.root/'recovered.zip';self.manifest=self.root/'manifest.json'
        self.names=['detail-'+str(i)*64+'.html' for i in [1,2,3]];self.bodies=[('voucher '+str(i)).encode()*30 for i in [1,2,3]]
        with zipfile.ZipFile(self.part,'w',compression=zipfile.ZIP_DEFLATED) as z:
            for name,body in zip(self.names,self.bodies):z.writestr(name,body)
        with zipfile.ZipFile(self.part) as z:self.infos=z.infolist();self.central=z.start_dir
        self.manifest.write_text(json.dumps([{'file':name,'url':'https://egramswaraj.gov.in/voucherWiseReport.do?state=9&village=82056&finYear=2026-2027&month='+str(i)} for i,name in enumerate(self.names)]))
        self.set_time()
    def set_time(self):
        stamp=datetime(2026,11,15,2,0,tzinfo=timezone.utc).timestamp();os.utime(self.part,(stamp,stamp))
    def tearDown(self):self.temp.cleanup()
    def test_complete_entries_recover_exactly_with_manifest_binding_and_day_precision(self):
        self.part.write_bytes(self.part.read_bytes()[:self.central]);self.set_time();before=self.part.read_bytes()
        note=recover(self.part,self.manifest,self.output);self.assertEqual(3,note['complete_entries']);self.assertEqual(hashlib.sha256(before).hexdigest(),note['original_sha256']);self.assertEqual(before,self.part.read_bytes())
        with zipfile.ZipFile(self.output) as z:
            for name,body in zip(self.names,self.bodies):self.assertEqual(body,z.read(name))
            meta=[json.loads(x) for x in z.read('_capture-recovered.jsonl').decode().splitlines()];self.assertEqual('day',meta[0]['captured_at_precision']);self.assertEqual(json.loads(self.manifest.read_text())[0]['url'],meta[0]['url'])
    def test_torn_last_entry_is_retained_in_original_and_not_published(self):
        raw=self.part.read_bytes();info=self.infos[2];fields=struct.unpack_from('<IHHHHHIIIHH',raw,info.header_offset);start=info.header_offset+30+fields[9]+fields[10];self.part.write_bytes(raw[:start+max(1,info.compress_size//2)]);self.set_time()
        note=recover(self.part,self.manifest,self.output);self.assertEqual(2,note['complete_entries']);self.assertGreater(note['unrecovered_bytes'],0)
        with zipfile.ZipFile(self.output) as z:self.assertNotIn(self.names[2],z.namelist())
    def test_unknown_source_and_ambiguous_retrieval_day_are_rejected(self):
        values=json.loads(self.manifest.read_text());values[0]['url']='https://example.com/foreign';self.manifest.write_text(json.dumps(values))
        with self.assertRaisesRegex(ValueError,'Non-official'):recover(self.part,self.manifest,self.output)
        values[0]['url']='https://egramswaraj.gov.in/report';self.manifest.write_text(json.dumps(values));future=datetime(2026,11,16,2,tzinfo=timezone.utc).timestamp();os.utime(self.part,(future,future))
        with self.assertRaisesRegex(ValueError,'day is not unambiguous'):recover(self.part,self.manifest,self.output)
    def test_bad_crc_cannot_publish_a_damaged_body(self):
        raw=bytearray(self.part.read_bytes());struct.pack_into('<I',raw,14,0);self.part.write_bytes(raw);self.set_time()
        with self.assertRaisesRegex(ValueError,'No complete CRC'):recover(self.part,self.manifest,self.output)
        self.assertFalse(self.output.exists())

if __name__=='__main__':unittest.main()
