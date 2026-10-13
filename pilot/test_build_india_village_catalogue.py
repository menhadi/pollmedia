import contextlib
import io
import json
import sqlite3
import tempfile
import unittest
from pathlib import Path
from build_india_village_catalogue import build, identify_state


class IndiaVillageCatalogueTest(unittest.TestCase):
    def test_territory_aliases_have_canonical_state_names(self):
        self.assertEqual('Andaman and Nicobar Islands', identify_state(Path('maps/andaman-nicobar-source-villages/nicobars.geojson'), {}))
        self.assertEqual('Dadra and Nagar Haveli and Daman and Diu', identify_state(Path('maps/dnh-daman-diu-source-villages/diu.geojson'), {}))

    def test_duplicate_and_invalid_shapes_are_audited_without_inventing_joins(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'public/maps').mkdir(parents=True)
            geometry = {'type':'Polygon','coordinates':[[[79,28],[80,28],[80,29],[79,28]]]}
            features = [
                {'type':'Feature','id':10,'properties':{'name':'Same name','source_code':'123456'},'geometry':geometry},
                {'type':'Feature','id':11,'properties':{'name':'Same name','source_code':'123456'},'geometry':{'type':'Polygon','coordinates':[[[80,28],[81,28],[81,29],[80,28]]]}},
                {'type':'Feature','id':12,'properties':{'name':'Broken'},'geometry':{'type':'Polygon','coordinates':[[[200,28],[201,28],[201,29],[200,28]]]}}
            ]
            features.append({'type':'Feature','id':13,'properties':{'name':'Same name','source_code':'123456'},'geometry':geometry})
            data = {'type':'FeatureCollection','metadata':{'source':'https://surveyofindia.gov.in/documents/UTTAR_PRADESH.zip'},'features':features}
            (root / 'public/maps/test-villages.geojson').write_text(json.dumps(data))
            (root / 'public/maps/duplicate-villages.geojson').write_text(json.dumps(data))
            with contextlib.redirect_stdout(io.StringIO()):
                catalogue = build(root / 'public', root / 'output')
            self.assertEqual(2, catalogue['audit']['indexed_shapes'])
            self.assertEqual(1, len(catalogue['audit']['duplicate_files']))
            self.assertEqual(1, len(catalogue['audit']['structural_errors']))
            db = sqlite3.connect(root / 'output/villages.sqlite')
            rows = db.execute('SELECT id,properties FROM places').fetchall()
            self.assertNotEqual(rows[0][0], rows[1][0])
            self.assertNotIn('census_2011', json.loads(rows[0][1]))
            self.assertEqual(1, db.execute('SELECT count(*) FROM feature_aliases').fetchone()[0])
            self.assertEqual(2, db.execute("SELECT count(*) FROM search WHERE search MATCH '\"Same\"*'").fetchone()[0])
            db.close()


if __name__ == '__main__':
    unittest.main()
