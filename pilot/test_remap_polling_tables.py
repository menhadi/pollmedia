import hashlib
import json
import tempfile
import unittest
from pathlib import Path

from remap_polling_tables import remap
from extract_polling_sources import ADAPTER, MAPPING_NOTE


class RemapPollingTablesTest(unittest.TestCase):
    def test_existing_rows_and_page_checksums_remain_unchanged(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            digest = 'a' * 64
            folder = root/('b' * 24)/(digest+'-tables')
            folder.mkdir(parents=True)
            existing = {'page': 1, 'tables': [{'number': 1, 'cells': [['unmapped']]}],
                        'polling_rows': [{'source_table_row': 1}], 'notes': []}
            cells = [
                ['Name of the Polling Station', None, 'No. of valid votes cast in favour of',
                 'Total of Valid Votes', 'No. of rejected votes', 'NOTA', 'Total'],
                [None, None, 'Candidate A', None, None, None, None],
                ['No.', 'Name', 'P1', None, None, None, None],
                ['1', 'School', '5', '5', '0', '1', '6'],
            ]
            unmapped = {'page': 2, 'tables': [{'number': 1, 'cells': cells}],
                        'polling_rows': [], 'notes': [MAPPING_NOTE]}
            pages = []
            for number, data in enumerate((existing, unmapped), 1):
                body = json.dumps(data).encode()
                name = f'{number}.json'
                (folder/name).write_bytes(body)
                pages.append({'page': number, 'file': name,
                              'sha256': hashlib.sha256(body).hexdigest(),
                              'tables': 1, 'polling_rows': len(data['polling_rows']),
                              'flagged_rows': 0, 'notes': data['notes']})
            index = {'adapter': 'form20-grid-v5', 'pages': pages, 'polling_rows': 1}
            (folder/'index.json').write_text(json.dumps(index))

            remap(root, source_sha256=digest)

            updated = json.loads((folder/'index.json').read_text())
            self.assertEqual(updated['adapter'], ADAPTER)
            self.assertEqual(updated['pages'][0], pages[0])
            self.assertEqual((folder/'1.json').read_bytes(), json.dumps(existing).encode())
            self.assertEqual(updated['polling_rows'], 2)
            mapped = json.loads((folder/updated['pages'][1]['file']).read_text())
            self.assertEqual(mapped['polling_rows'][0]['polling_station'], '1 School')


if __name__ == '__main__':
    unittest.main()
