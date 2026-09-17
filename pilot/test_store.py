"""Synthetic changes stay in temporary databases, never the real-data sample."""
import copy,json,tempfile,unittest
from pathlib import Path
from store import ingest,current,connect,ROOT

class SyncTests(unittest.TestCase):
    def setUp(self):
        self.tmp=tempfile.TemporaryDirectory();self.db=Path(self.tmp.name)/'test.sqlite'
        self.payload=json.loads((ROOT/'data'/'sample.json').read_text(encoding='utf-8'))
    def tearDown(self):self.tmp.cleanup()
    def test_unchanged_is_idempotent(self):
        self.assertEqual(ingest(self.payload,self.db),'updated')
        self.assertEqual(ingest(self.payload,self.db),'unchanged')
        with connect(self.db) as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM releases').fetchone()[0],1)
    def test_new_release_replaces_without_duplicates_and_retains_history(self):
        ingest(self.payload,self.db)
        changed=copy.deepcopy(self.payload)
        changed['sir']['parts'][0]['counts']['death']+=1
        changed['sir']['parts'][0]['listed_records']+=1
        changed['sir']['sha256']='synthetic-test-only'
        self.assertEqual(ingest(changed,self.db),'updated')
        self.assertEqual(current(self.db)['sir']['parts'],changed['sir']['parts'])
        with connect(self.db) as db:self.assertEqual(db.execute('SELECT COUNT(*) FROM releases').fetchone()[0],2)
    def test_invalid_or_duplicate_release_preserves_good_data(self):
        ingest(self.payload,self.db)
        for mode in ['count','duplicate']:
            invalid=copy.deepcopy(self.payload)
            if mode=='count':invalid['sir']['parts'][0]['listed_records']+=10
            else:invalid['sir']['parts'].append(invalid['sir']['parts'][0])
            with self.assertRaises(ValueError):ingest(invalid,self.db)
            self.assertEqual(current(self.db)['sir'],self.payload['sir'])

if __name__=='__main__':unittest.main()
