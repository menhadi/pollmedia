"""Use standard HTTP byte ranges to recover a truncated large download."""
from acquire import ROOT, SOURCES
import urllib.request, concurrent.futures, hashlib, json, zipfile, io
from datetime import datetime, timezone

s = SOURCES[2]
with urllib.request.urlopen(urllib.request.Request(s['url'], method='HEAD'), timeout=30) as r:
    size = int(r.headers['Content-Length'])
    etag = r.headers.get('ETag')

def chunk(start):
    end = min(start + 1_000_000, size) - 1
    cache=ROOT/'tmp'/'boundary-chunks'
    cache.mkdir(exist_ok=True,parents=True)
    dest=cache/str(start)
    if dest.exists() and dest.stat().st_size==end-start+1:
        return dest.read_bytes()
    req = urllib.request.Request(s['url'], headers={'Range': f'bytes={start}-{end}', 'If-Range': etag})
    with urllib.request.urlopen(req, timeout=60) as r:
        b = r.read()
        assert r.status == 206 and len(b) == end-start+1, (r.status, start, len(b))
        assert r.headers['Content-Range'].startswith(f'bytes {start}-{end}/')
        dest.write_bytes(b)
        return b

with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
    data = b''.join(pool.map(chunk, range(0, size, 1_000_000)))
with zipfile.ZipFile(io.BytesIO(data)) as z:
    assert z.testzip() is None
digest = hashlib.sha256(data).hexdigest()
dest = ROOT / 'raw' / digest / s['filename']
dest.parent.mkdir(exist_ok=True, parents=True)
dest.write_bytes(data)
manifest = json.loads((ROOT/'acquisition.json').read_text())
old = next(i for i in manifest if i['id'] == s['id'])
old.update(status='validated', previous_truncated_sha256=old['sha256'], sha256=digest, bytes=len(data), path=str(dest.relative_to(ROOT)), checked_at=datetime.now(timezone.utc).isoformat(), etag=etag)
(ROOT/'acquisition.json').write_text(json.dumps(manifest, indent=2), encoding='utf-8')
print(json.dumps(old, indent=2))
