import sys,json,hashlib,unittest
from pathlib import Path
sys.path.insert(0,'D:/pollmedia/tmp/ac-pilot-deps')
from shapely.geometry import shape
from shapely.ops import unary_union
ROOT=Path(__file__).resolve().parents[1]
class BoundaryContextTest(unittest.TestCase):
 def test_context_does_not_modify_source_or_invent_villages(self):
  for slug,count in [('pondicherry',69),('karaikal',30)]:
   source=ROOT/'application/public/maps/puducherry-source-villages'/f'{slug}.geojson';d=json.loads(source.read_text(encoding='utf8'));c=json.loads((ROOT/'application/public/maps'/f'puducherry-{slug}-boundary-context.geojson').read_text(encoding='utf8'));self.assertEqual(len(d['features']),count);self.assertEqual(hashlib.sha256(source.read_bytes()).hexdigest(),c['metadata']['settlement_sha256']);geoms={f['properties']['kind']:shape(f['geometry']) for f in c['features']};settlements=unary_union([shape(f['geometry']) for f in d['features']]);self.assertTrue(all(g.is_valid for g in geoms.values()));self.assertLess(geoms['uncovered'].intersection(settlements).area,1e-12);self.assertLess(geoms['overlap'].symmetric_difference(settlements.intersection(geoms['tamil-nadu'])).area,1e-12);self.assertLess(geoms['uncovered'].symmetric_difference(geoms['outline'].difference(settlements)).area,1e-12)
if __name__=='__main__':unittest.main()
