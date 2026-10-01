"""Checks archived PDF turnout recovery and immutable correction package."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_ac_detailed_pdf_turnout_bundle import NAME, PC1967, read_pc1967_summary, read_printed_turnout, TARGETS


ROOT = Path(__file__).resolve().parents[1]
BUNDLE = ROOT / 'exports' / (NAME + '.zip')


class DetailedPdfTurnoutTests(unittest.TestCase):
    def test_source_pdf_matches_candidate_rows_and_printed_turnout(self):
        with zipfile.ZipFile(BUNDLE) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            self.assertEqual([len(item['codes']) for item in audit['editions']], [11, 1, 2])
            expected = {'c7a9e523186004c713736633': {141: 221323, 173: 228115, 394: 206026},
                        'ed5e5cef04a9edb821cf27af': {11: 19567}}
            for item in audit['editions']:
                edition = item['edition']
                if edition == PC1967:
                    continue
                snapshot = zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{edition}.zip')))
                before = json.loads(snapshot.read(
                    f'election-archive/{edition}/extraction-{item["previous_sha256"]}.json'))
                source = ROOT / 'application/storage/app/private/election-archive' / edition / item['pdf_file']
                self.assertEqual(hashlib.sha256(source.read_bytes()).hexdigest(), item['pdf_sha256'])
                found = read_printed_turnout(source, before['records'], TARGETS[edition], len(before['records']))
                for code, voters in expected[edition].items():
                    self.assertEqual(found[code]['votes_polled'], voters)
                altered = json.loads(json.dumps(before['records']))
                record = next(r for r in altered if r['code'] == min(TARGETS[edition]))
                record['candidates'][0]['votes'] += 1
                with self.assertRaisesRegex(ValueError, 'arithmetic differs'):
                    read_printed_turnout(source, altered, TARGETS[edition], len(altered))

    def test_1967_delhi_pc_summary_has_positive_printed_voters(self):
        with zipfile.ZipFile(BUNDLE) as outer:
            item = next(d for d in json.loads(outer.read('AUDIT.json'))['editions'] if d['edition'] == PC1967)
            snapshot = zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{PC1967}.zip')))
            before = json.loads(snapshot.read(
                f'election-archive/{PC1967}/extraction-{item["previous_sha256"]}.json'))
            source = ROOT / 'application/storage/app/private/election-archive' / PC1967 / item['pdf_file']
            found = read_pc1967_summary(source, before['records'])
            self.assertEqual(found[500]['votes_polled'], 152918)
            self.assertEqual(found[501]['votes_polled'], 200365)
            self.assertEqual(found[500]['valid_votes'] + found[500]['rejected_votes'], 152918)

    def test_bundle_preserves_existing_election_data(self):
        self.assertEqual(hashlib.sha256(BUNDLE.read_bytes()).hexdigest(),
                         BUNDLE.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(BUNDLE) as outer:
            self.assertIsNone(outer.testzip())
            self.assertIn('10 GiB', outer.read('IMPORT.sh').decode())
            audit = json.loads(outer.read('AUDIT.json'))
            for item in audit['editions']:
                edition = item['edition']
                snapshot = zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{edition}.zip')))
                correction = zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{edition}.zip')))
                before = json.loads(snapshot.read(
                    f'election-archive/{edition}/extraction-{item["previous_sha256"]}.json'))
                after = json.loads(correction.read(f'election-archive/{edition}/extraction.json'))
                manifest = json.loads(correction.read('manifest.json'))['files'][0]
                self.assertEqual(manifest['replaces_sha256'], item['previous_sha256'])
                for prior, revised in zip(before['records'], after['records']):
                    self.assertEqual(prior['candidates'], revised['candidates'])
                    self.assertEqual(prior['status'], revised['status'])
                    if prior.get('votes_polled') not in (None, 0):
                        self.assertEqual(prior['votes_polled'], revised['votes_polled'])


if __name__ == '__main__':
    unittest.main()
