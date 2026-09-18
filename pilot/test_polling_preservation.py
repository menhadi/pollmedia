import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile
from preserve_polling_sources import preserve


class PreservationTest(unittest.TestCase):
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
            (tables/'1.json').write_bytes(b'changed')
            with self.assertRaisesRegex(ValueError,'checksum changed'):
                preserve(root,root/'changed.zip')
            self.assertFalse((root/'changed.zip').exists())


if __name__ == '__main__':
    unittest.main()
