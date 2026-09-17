"""Download explicitly identified official pilot sources; retain hashed originals."""
import concurrent.futures
import hashlib
import json
from pathlib import Path
from datetime import datetime, timezone
import urllib.request
import zipfile, io

ROOT = Path(__file__).resolve().parent
SOURCES = [
    dict(id='census-pilibhit-2011', filename='pilibhit-pca-2011.xlsx', url='https://censusindia.gov.in/nada/index.php/catalog/6342/download/9419/DDW_PCA0920_2011_MDDS%20with%20UI.xlsx', landing='https://censusindia.gov.in/nada/index.php/catalog/6342'),
    dict(id='sir-pilibhit-uncollected', filename='pilibhit-sir-partwise.pdf', url='https://cdn.s3waas.gov.in/s30aa1883c6411f7873cb83dacb17b0afc/uploads/2025/12/17659662732831.pdf', landing='https://pilibhit.nic.in/meeting-blo-bla/'),
    dict(id='soi-up-villages', filename='uttar-pradesh-villages.zip', url='https://surveyofindia.gov.in/documents/UTTAR_PRADESH.zip', landing='https://surveyofindia.gov.in/pages/village-boundary-data-base-of-entire-india'),
]

def acquire(source):
    result = dict(source, checked_at=datetime.now(timezone.utc).isoformat())
    try:
        with urllib.request.urlopen(source['url'], timeout=60) as response:
            length = int(response.headers.get('Content-Length', 0))
            if length > 200_000_000:
                return dict(result, status='size_review', bytes=length)
            data = response.read(200_000_001)
            if length and len(data)!=length:
                raise ValueError(f'Incomplete download: {len(data)} of {length} bytes')
            if len(data) > 200_000_000:
                raise ValueError('Source exceeds pilot download limit')
            expected = b'%PDF' if source['filename'].endswith('.pdf') else b'PK'
            if not data.startswith(expected):
                raise ValueError('Response is not the expected file format')
            if expected == b'PK':
                with zipfile.ZipFile(io.BytesIO(data)) as z:
                    if z.testzip() is not None:
                        raise ValueError('Archive integrity failure')
            digest = hashlib.sha256(data).hexdigest()
            dest = ROOT / 'raw' / digest / source['filename']
            dest.parent.mkdir(parents=True, exist_ok=True)
            unchanged = dest.exists()
            if not unchanged:
                dest.write_bytes(data)
            return dict(result, status='unchanged' if unchanged else 'downloaded', sha256=digest, bytes=len(data), path=str(dest.relative_to(ROOT)), content_type=response.headers.get('Content-Type'), last_modified=response.headers.get('Last-Modified'))
    except Exception as error:
        return dict(result, status='error', error=str(error))

if __name__ == '__main__':
    import sys
    selected=[s for s in SOURCES if not sys.argv[1:] or s['id'] in sys.argv[1:]]
    with concurrent.futures.ThreadPoolExecutor(max_workers=3) as pool:
        fetched = list(pool.map(acquire, selected))
    previous={r['id']:r for r in json.loads((ROOT/'acquisition.json').read_text())} if (ROOT/'acquisition.json').exists() else {}
    for r in fetched:
        if r['status']=='error' and r['id'] in previous:
            previous[r['id']]['last_check_error']=r['error']
            previous[r['id']]['last_failed_check_at']=r['checked_at']
        else:
            previous[r['id']]=r
    results=list(previous.values())
    (ROOT / 'acquisition.json').write_text(json.dumps(results, indent=2), encoding='utf-8')
    print(json.dumps(results, indent=2))
