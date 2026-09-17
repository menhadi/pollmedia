"""Atomic, versioned sample imports. Production will use PostgreSQL."""
import hashlib,json,sqlite3
from pathlib import Path
from datetime import datetime,timezone
from contextlib import contextmanager
ROOT=Path(__file__).resolve().parent
DB=ROOT/'data'/'pilot.sqlite'

@contextmanager
def connect(path=DB):
    db=sqlite3.connect(path)
    db.execute('CREATE TABLE IF NOT EXISTS releases (hash TEXT PRIMARY KEY, imported_at TEXT NOT NULL, payload TEXT NOT NULL)')
    db.execute('CREATE TABLE IF NOT EXISTS active (slot INTEGER PRIMARY KEY CHECK(slot=1), hash TEXT NOT NULL)')
    try:
        with db:
            yield db
    finally:
        db.close()

def ingest(payload,path=DB):
    parts=payload['sir']['parts']
    keys=[(p['ac'],p['part']) for p in parts]
    if not parts or len(keys)!=len(set(keys)):
        raise ValueError('Empty or duplicate part keys')
    for p in parts:
        if any(not isinstance(n,int) or n<0 for n in p['counts'].values()) or sum(p['counts'].values())!=p['listed_records']:
            raise ValueError('Part counts do not reconcile')
        if p['ac']!=payload['sir']['ac']:
            raise ValueError('AC mismatch')
    blob=json.dumps(payload,ensure_ascii=False,sort_keys=True)
    digest=hashlib.sha256(blob.encode()).hexdigest()
    with connect(path) as db:
        old=db.execute('SELECT hash FROM active WHERE slot=1').fetchone()
        if old and old[0]==digest: return 'unchanged'
        db.execute('INSERT OR IGNORE INTO releases VALUES (?,?,?)',(digest,datetime.now(timezone.utc).isoformat(),blob))
        db.execute('INSERT INTO active VALUES(1,?) ON CONFLICT(slot) DO UPDATE SET hash=excluded.hash',(digest,))
    return 'updated'

def current(path=DB):
    with connect(path) as db:
        r=db.execute('SELECT payload,imported_at FROM releases JOIN active USING(hash) WHERE slot=1').fetchone()
    if not r: raise ValueError('No accepted import')
    p=json.loads(r[0]);p['imported_at']=r[1]
    return p

if __name__=='__main__':
    print(ingest(json.loads((ROOT/'data'/'sample.json').read_text(encoding='utf-8'))))
