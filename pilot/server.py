"""Loopback-only pilot; exposes approved aggregates, never raw source files."""
import json
from http.server import ThreadingHTTPServer,BaseHTTPRequestHandler
from urllib.parse import urlparse,parse_qs
from store import ROOT,current

class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        req=urlparse(self.path)
        if req.path in ('/','/india/sir'):
            return self.send(200,(ROOT/'web'/'index.html').read_bytes(),'text/html; charset=utf-8')
        if req.path=='/api/sir':
            query=parse_qs(req.query)
            state=query.get('state',[''])[0];pc=query.get('pc',[''])[0];ac=query.get('ac',[''])[0]
            data=current();s=data['sir']
            try:page=max(1,int(query.get('page',['1'])[0]))
            except ValueError:return self.send(400,b'{"error":"Invalid page"}')
            valid=state=='09' and pc in ('','26') and ac in ('','127') and (pc or ac)
            rows=s['parts'] if valid else []
            q=query.get('q',[''])[0].strip().casefold()
            if q:rows=[p for p in rows if q in p['name'].casefold() or q==str(p['part'])]
            result={k:v for k,v in s.items() if k!='parts'}
            result.update(rows=rows[(page-1)*10:page*10],total_rows=len(rows),listed_records=sum(p['listed_records'] for p in rows),page=page,imported_at=data['imported_at'],partial_coverage=True)
            return self.send(200,json.dumps(result).encode())
        if req.path=='/api/census':
            return self.send(200,json.dumps(current()['census']).encode())
        if req.path=='/api/geography':
            d=json.loads((ROOT/'data'/'crosswalk.json').read_text());d.pop('rows')
            return self.send(200,json.dumps(d).encode())
        return self.send(404,b'{"error":"Not found"}')
    def send(self,status,body,kind='application/json'):
        self.send_response(status);self.send_header('Content-Type',kind)
        self.send_header('Cache-Control','no-store');self.send_header('X-Content-Type-Options','nosniff')
        self.send_header('Content-Length',str(len(body)));self.end_headers();self.wfile.write(body)
    def log_message(self,*args):pass

if __name__=='__main__':
    print('Pollmedia pilot: http://127.0.0.1:8765/india/sir',flush=True)
    ThreadingHTTPServer(('127.0.0.1',8765),Handler).serve_forever()
