import io
import json
import tempfile
import unittest
import zipfile
from pathlib import Path

from extract_official_village_attributes import extract, geojson_features
from verify_soi_village_identities import attribute_rows, source_fields, same_source_feature


class OfficialAttributeExtractionTest(unittest.TestCase):
    def test_streaming_preserves_original_unicode_and_record_order(self):
        source={'type':'FeatureCollection','features':[{'type':'Feature','properties':{'name':'गाँव'},'geometry':None},{'type':'Feature','properties':{'name':'Second'},'geometry':None}]}
        class SmallChunks(io.BytesIO):
            def read(self,size=-1):
                return super().read(min(size,7))
        features=list(geojson_features(SmallChunks(json.dumps(source,ensure_ascii=False).encode())))
        self.assertEqual(source['features'],features)
        with self.assertRaisesRegex(ValueError,'Truncated'):
            list(geojson_features(io.BytesIO(b'{"features":[{"type":"Feature"')))

    def test_changed_archive_is_rejected_before_any_attributes_are_written(self):
        with tempfile.TemporaryDirectory() as temporary:
            path=Path(temporary)/'source.zip';output=path.with_suffix('.jsonl')
            with zipfile.ZipFile(path,'w') as archive:
                archive.writestr('Original.GeoJSON','{"features":[]}')
            with self.assertRaisesRegex(ValueError,'edition differs'):
                extract(path,'0'*64,output)
            self.assertFalse(output.exists())

    def test_nwic_schema_retains_full_codes_and_each_original_identifier(self):
        with tempfile.TemporaryDirectory() as temporary:
            path=Path(temporary)/'source.jsonl'
            path.write_text(json.dumps({'record':17,'fields':{'id':41,'objectid_1':41,'objectid':4579.0,'stcode':'01','dtcode':'001','sdcode':'0054','vlcode':'003612','village':'Original spelling','subdistric':'Original tehsil','district':'Original district','total_urban_rural\n':'Rural'}})+'\n')
            rows=list(attribute_rows({'dbf':str(path),'format':'nwic-jsonl'}))
            self.assertEqual(['41','41','4579','16'],[row['OBJECTID'] for _,row in rows])
            self.assertEqual('003612',source_fields(rows[0][1])['Vill_LGD'])
            self.assertTrue(all(number==17 and row['State_LGD']=='01' and row['Subdis_LGD']=='0054' for number,row in rows))
            raw=rows[-1][1]
            properties={'source_code':'003612','name':'Original spelling','subdistrict':'Original tehsil','source_district':'Original district','source_record_id':41}
            self.assertTrue(same_source_feature(properties,'16',{},raw))
            self.assertFalse(same_source_feature(dict(properties,source_record_id=42),'16',{},raw))
            self.assertFalse(same_source_feature(dict(properties,source_district='Other district'),'16',{},raw))


if __name__=='__main__':
    unittest.main()
