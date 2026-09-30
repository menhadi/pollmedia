import hashlib
import json
from pathlib import Path
import tempfile
import unittest

from reconcile_gujarat_2012_form20 import apply, total_table


class ReconcileGujaratForm20Test(unittest.TestCase):
    def test_total_requires_printed_arithmetic(self):
        cells = [['', 'ALICE', 'BOB', 'Total of valid votes'],
                 ['Total', '7', '3', '10']]
        self.assertIsNone(total_table(cells))  # Page header rows are required.
        cells.insert(1, ['', '', '', ''])
        cells.insert(2, ['', '', '', ''])
        self.assertEqual(total_table(cells), ({1: 'ALICE', 2: 'BOB'}, [7, 3], 10))
        cells[-1][-1] = '11'
        self.assertIsNone(total_table(cells))

    def test_apply_preserves_exact_old_bytes_and_source_hashes(self):
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            archive = root / 'application/storage/app/private/election-archive/503135d3e838d38c93d3bce7'
            archive.mkdir(parents=True)
            source = b'official report'
            (archive / 'report.pdf').write_bytes(source)
            data = {'source_file': 'report.pdf', 'source_sha256': hashlib.sha256(source).hexdigest(),
                    'records': [{'code': 1, 'candidates': [{'candidate_name': 'ALICE', 'votes': None}]}]}
            old = json.dumps(data, indent=2).encode()
            (archive / 'extraction.json').write_bytes(old)
            polling = root / 'application/storage/app/private/polling-station-sources'
            polling.mkdir(parents=True)
            pdf = b'official form 20'
            digest = hashlib.sha256(pdf).hexdigest()
            (polling / (digest + '.pdf')).write_bytes(pdf)
            url = 'https://example.gov.in/AC001.PDF'
            (polling / 'index.json').write_text(json.dumps({'sources': [
                {'source_url': url, 'sha256': digest, 'folder': '.'}]}), encoding='utf-8')
            result = {'proposals': [{'record_code': 1, 'candidate_index': 0,
                'candidate_name': 'ALICE', 'votes': 10, 'source_url': url,
                'source_sha256': digest, 'page': 2, 'page_sha256': 'a' * 64,
                'table': 1, 'column': 2, 'known_matches': 3}]}
            changed = apply(root, result)
            self.assertEqual(changed['corrected_votes'], 1)
            self.assertEqual((archive / ('extraction-' + hashlib.sha256(old).hexdigest() + '.json')).read_bytes(), old)
            new = json.loads((archive / 'extraction.json').read_text(encoding='utf-8'))
            self.assertEqual(new['records'][0]['candidates'][0]['votes'], 10)
            self.assertEqual(new['records'][0]['candidates'][0]['vote_evidence']['source_sha256'], digest)


if __name__ == '__main__':
    unittest.main()
