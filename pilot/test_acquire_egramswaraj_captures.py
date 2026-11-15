import http.server
import json
import shutil
import subprocess
import tempfile
import threading
import time
import unittest
import zipfile
from pathlib import Path

@unittest.skipUnless(shutil.which('pwsh'),'PowerShell is required for archive timing integration')
class CaptureFlushTest(unittest.TestCase):
    def test_small_completed_capture_flushes_while_another_request_is_still_running(self):
        class Handler(http.server.BaseHTTPRequestHandler):
            def do_GET(self):
                if self.path=='/slow':time.sleep(5)
                body=b'public test body';self.send_response(200);self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
            def log_message(self,*args):pass
        server=http.server.ThreadingHTTPServer(('127.0.0.1',0),Handler);thread=threading.Thread(target=server.serve_forever,daemon=True);thread.start()
        try:
            with tempfile.TemporaryDirectory() as folder:
                root=Path(folder);base='http://127.0.0.1:'+str(server.server_port)
                manifest=[{'file':'one.txt','url':base+'/fast'},{'file':'two.txt','url':base+'/slow'},{'file':'three.txt','url':base+'/last'}]
                (root/'manifest.json').write_text(json.dumps(manifest))
                child=subprocess.Popen(['pwsh','-NoProfile','-File',str(Path(__file__).with_name('acquire_egramswaraj_captures.ps1')),'-Root',folder,'-Manifest','manifest.json','-Prefix','test','-Concurrency','1','-BatchSize','50','-FlushSeconds','1'],stdin=subprocess.DEVNULL,stdout=subprocess.PIPE,stderr=subprocess.STDOUT,text=True,creationflags=getattr(subprocess,'CREATE_NO_WINDOW',0))
                early=False;deadline=time.monotonic()+30
                while time.monotonic()<deadline and child.poll() is None:
                    if list(root.glob('test-*.zip')):early=True;break
                    time.sleep(.1)
                output,_=child.communicate(timeout=60);self.assertEqual(0,child.returncode,output);self.assertTrue(early,'First completed capture waited for the slow request')
                records=[]
                for p in root.glob('test-*.zip'):
                    with zipfile.ZipFile(p) as z:
                        for name in z.namelist():
                            if name.startswith('_capture-'):records.extend(json.loads(x) for x in z.read(name).decode().splitlines())
                            else:self.assertEqual(b'public test body',z.read(name))
                self.assertEqual({r['file']:r['url'] for r in manifest},{r['file']:r['url'] for r in records});self.assertEqual(3,len(records));self.assertFalse(list(root.glob('*.part')))
        finally:server.shutdown();server.server_close()

if __name__=='__main__':unittest.main()
