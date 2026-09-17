import hashlib
import json
import tempfile
import unittest
from pathlib import Path

from extract_state_election_2007 import extract, save
from extract_historical_elections import modern_record, save_modern


class HistoricalExtractionTest(unittest.TestCase):
    def test_modern_archive_excludes_nota_and_retains_conflicting_rows(self):
        payload = dict(electors=100, votes_polled=95, valid_candidate_votes=50,
                       candidates=[dict(candidate_name=n, party_at_election=p, votes=v, general_votes=v, postal_votes=0)
                                   for n, p, v in [('A', 'P1', 30), ('B', 'P2', 20), ('NOTA', 'NOTA', 40)]])
        item = dict(code=1, name='Seat', payload=payload)
        record = modern_record(item)
        self.assertEqual(record['winner'], 'A')
        self.assertEqual(record['margin'], 10)
        payload['candidates'][0]['postal_votes'] = 1
        record = modern_record(item)
        self.assertEqual(record['status'], 'needs_review')
        self.assertEqual(len(record['candidates']), 3)
        self.assertNotIn('winner', record)
        self.assertEqual(modern_record(dict(code=2, name='Missing', error='Summary absent'))['error'], 'Summary absent')

    def test_modern_archive_rejects_changed_source_before_parsing(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'report.pdf').write_bytes(b'changed')
            (root / 'manifest.json').write_text(json.dumps(dict(kind='ac', year=2012, files=[dict(name='2012.pdf', file='report.pdf', sha256=hashlib.sha256(b'original').hexdigest())])), encoding='utf-8')
            with self.assertRaisesRegex(ValueError, 'checksum differs'):
                save_modern(root / 'manifest.json')
            self.assertFalse((root / 'extraction.json').exists())

    def test_official_report_full_coverage_wrapped_party_and_conflicting_totals(self):
        root = Path(__file__).resolve().parent.parent / 'application/storage/app/private/election-archive/174ec81b511a8fb1aeca553f'
        records = extract(root / '174ec81b511a8fb1aeca553f-7507.pdf')
        self.assertEqual(len(records), 403)
        self.assertEqual(sum(len(r['candidates']) for r in records), 6086)
        self.assertEqual(sum(r['status'] == 'validated' for r in records), 386)
        self.assertEqual(records[0]['name'], 'SEOHARA')
        self.assertEqual(records[0]['winner'], 'YASH PAL SINGH')
        self.assertEqual(records[0]['margin'], 14878)
        self.assertEqual(records[43]['name'], 'PURANPUR')
        self.assertEqual(records[43]['status'], 'needs_review')
        self.assertIn('170056', records[43]['error'])
        self.assertIn('170060', records[43]['error'])
        self.assertNotIn('winner', records[43])
        self.assertEqual(records[43]['candidates'][5]['party_at_election'], 'CPI(ML)(L)')
        self.assertEqual(records[1]['candidates'][1]['candidate_name'], 'THAKUR MOOL CHAND CHAUHAN')

    def test_changed_source_is_rejected_before_pdf_parsing(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'report.pdf').write_bytes(b'changed')
            (root / 'manifest.json').write_text(json.dumps(dict(kind='ac', year=2007, files=[dict(file='report.pdf', sha256=hashlib.sha256(b'original').hexdigest())])), encoding='utf-8')
            with self.assertRaisesRegex(ValueError, 'checksum differs'):
                save(root / 'manifest.json')
            self.assertFalse((root / 'extraction.json').exists())


if __name__ == '__main__':
    unittest.main()
