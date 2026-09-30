import hashlib
import json
from pathlib import Path
from tempfile import TemporaryDirectory
import unittest

from audit_pc_ac_gaps import summarize


class PcAcGapAuditTest(unittest.TestCase):
    def test_repeated_catalogue_url_is_one_edition_and_names_keep_codes(self):
        with TemporaryDirectory() as directory:
            root = Path(directory)
            fixtures = root / 'application/database/fixtures'
            fixtures.mkdir(parents=True)
            url = 'https://eci.gov.in/assembly/example'
            (fixtures / 'eci-election-archive.json').write_text(json.dumps({'pc': [], 'ac': [['2007', url]]}))
            (fixtures / 'eci-assembly-national.json').write_text(json.dumps({'entries': [
                {'state': 'Uttar Pradesh', 'year': 2007, 'label': '2007', 'url': url}]}))
            folder = root / 'application/storage/app/private/election-archive' / hashlib.sha256(url.encode()).hexdigest()[:24]
            folder.mkdir(parents=True)
            (folder / 'extraction.json').write_text(json.dumps({'source_url': url, 'kind': 'ac', 'year': 2007,
                'records': [
                    {'code': 1, 'name': 'Nawabganj', 'candidates': [{'votes': 1}]},
                    {'code': 2, 'name': 'Nawabganj', 'candidates': [{'votes': 2}]},
                ]}))

            report = summarize(root)

        self.assertEqual(report['catalogue_editions'], 1)
        self.assertEqual(report['constituency_records'], 2)
        self.assertEqual(report['duplicate_codes_within_edition'], [])
        self.assertEqual(len(report['same_name_different_codes']), 1)
        self.assertEqual(report['records_without_candidates'], [])


if __name__ == '__main__':
    unittest.main()
