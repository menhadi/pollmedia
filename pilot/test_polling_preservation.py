import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile
from preserve_polling_sources import preserve


class PreservationTest(unittest.TestCase):
    def test_state_snapshot_includes_current_ocr_without_stale_public_reference(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            folder = root/'application/storage/app/private/polling-station-sources'
            sources = []
            for state, state_id in [('CHANDIGARH', 'a'*24), ('SIKKIM', 'b'*24)]:
                base = folder/state_id
                original = ('%PDF-'+state).encode()
                digest = hashlib.sha256(original).hexdigest()
                base.mkdir(parents=True)
                (base/(digest+'.pdf')).write_bytes(original)
                ocr_dir = base/(digest+'-ocr')
                ocr_dir.mkdir()
                ocr_body = json.dumps({'text':'Unverified OCR', 'source_sha256':digest}).encode()
                (ocr_dir/'1.json').write_bytes(ocr_body)
                (ocr_dir/'index.json').write_text(json.dumps({'pages':[
                    {'page':1,'file':'1.json','sha256':hashlib.sha256(ocr_body).hexdigest()}
                ]}))
                sources.append({'state':state,'folder':state_id,'file':digest+'.pdf',
                                'sha256':digest,'pages':[]})
            (folder/'index.json').write_text(json.dumps({'states':[
                {'id':'a'*24,'state':'CHANDIGARH'}, {'id':'b'*24,'state':'SIKKIM'}
            ],'sources':sources}))
            (folder/'catalogue.json').write_text(json.dumps({'entries':[
                {'id':'a'*24,'state':'CHANDIGARH'}, {'id':'b'*24,'state':'SIKKIM'}
            ]}))
            output = root/'chandigarh.zip'
            preserve(root,output,['CHANDIGARH'])
            with zipfile.ZipFile(output) as archive:
                names = set(archive.namelist())
                self.assertTrue(any(name.endswith('-ocr/1.json') for name in names))
                self.assertTrue(any(name.endswith('-ocr/index.json') for name in names))
                self.assertFalse(any('/'+'b'*24+'/' in name for name in names))
                indexed = json.loads(archive.read('application/storage/app/private/polling-station-sources/index.json'))
                self.assertEqual([source['state'] for source in indexed['sources']], ['CHANDIGARH'])
                catalogue = json.loads(archive.read('application/storage/app/private/polling-station-sources/catalogue.json'))
                self.assertEqual([entry['state'] for entry in catalogue['entries']], ['CHANDIGARH'])
            data_only = root/'chandigarh-data.zip'
            preserve(root,data_only,['CHANDIGARH'],data_only=True)
            with zipfile.ZipFile(data_only) as archive:
                names = archive.namelist()
                self.assertFalse(any(name.endswith('.pdf') for name in names))
                self.assertTrue(any(name.endswith('-ocr/1.json') for name in names))
                self.assertIn('Database import data only',json.loads(archive.read('manifest.json'))['scope'])

    def test_snapshot_contains_original_and_page_and_rejects_changed_bytes(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            folder = root/'application/storage/app/private/polling-station-sources'
            state = 'a'*24
            original = b'%PDF-example-preserved-source'
            digest = hashlib.sha256(original).hexdigest()
            page = b'{"notes":["Source requires review"]}'
            base = folder/state
            tables = base/(digest+'-tables')
            tables.mkdir(parents=True)
            (base/(digest+'.pdf')).write_bytes(original)
            (tables/'1.json').write_bytes(page)
            (folder/'index.json').write_text(json.dumps({'states':[{'id':state}], 'sources':[
                {'folder':state, 'file':digest+'.pdf', 'sha256':digest,
                 'pages':[{'file':'1.json','sha256':hashlib.sha256(page).hexdigest()}]}]}))
            output = root/'snapshot.zip'
            preserve(root,output)
            with zipfile.ZipFile(output) as archive:
                manifest = json.loads(archive.read('manifest.json'))
                self.assertEqual(len(manifest['files']),3)
                self.assertIn('Partial',manifest['scope'])
                self.assertEqual(archive.read((tables/'1.json').relative_to(root).as_posix()),page)
            self.assertNotIn(b'\r',output.with_suffix('.sha256').read_bytes())
            index = json.loads((folder/'index.json').read_text())
            page_body = json.dumps({'pages': index['sources'][0]['pages']}).encode()
            page_hash = hashlib.sha256(page_body).hexdigest()
            page_file = digest+'-pages-'+page_hash[:16]+'.json'
            (base/page_file).write_bytes(page_body)
            index['sources'][0].update(pages=[], page_count=1, page_manifest={'file':page_file,'sha256':page_hash})
            (folder/'index.json').write_text(json.dumps(index))
            preserve(root,root/'compact.zip')
            with zipfile.ZipFile(root/'compact.zip') as archive:
                self.assertEqual(archive.read((base/page_file).relative_to(root).as_posix()), page_body)
                self.assertEqual(archive.read((tables/'1.json').relative_to(root).as_posix()), page)
            (base/page_file).write_bytes(b'changed')
            with self.assertRaisesRegex(ValueError, 'manifest checksum changed'):
                preserve(root,root/'bad-manifest.zip')
            (base/page_file).write_bytes(page_body)
            (tables/'1.json').write_bytes(b'changed')
            with self.assertRaisesRegex(ValueError,'checksum changed'):
                preserve(root,root/'changed.zip')
            self.assertFalse((root/'changed.zip').exists())


if __name__ == '__main__':
    unittest.main()
