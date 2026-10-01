"""Tests for source-matched residual Assembly turnout and guarded package bytes."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from build_pc_ac_residual_turnout_bundle import (
    GUJARAT2012, NAME, UP1951, read_gujarat2012, read_symbol_detail, read_up1951,
)


ROOT = Path(__file__).resolve().parents[1]
ARCHIVE = ROOT / 'application/storage/app/private/election-archive'


class ResidualTurnoutTests(unittest.TestCase):
    def test_up_1951_summary_matches_seat_and_candidate_votes(self):
        folder = ARCHIVE / UP1951
        data = json.loads((folder / 'extraction.json').read_text(encoding='utf-8'))
        found = read_up1951(folder / data['source_file'], data['records'])
        self.assertEqual(len(found), 7)
        self.assertEqual(found[53]['votes_polled'], 48102)
        self.assertEqual(found[53]['electors'], 81051)

    def test_jharkhand_2014_uses_full_detail_not_short_duplicate_fragment(self):
        edition = '775e12dc77eb9f634ba9a490'
        bundle = zipfile.ZipFile(ROOT / 'exports' / (NAME + '.zip'))
        audit = json.loads(bundle.read('AUDIT.json'))
        detail = next(d for d in audit['editions'] if d['edition'] == edition)
        inner = zipfile.ZipFile(io.BytesIO(bundle.read(f'snapshot-{edition}.zip')))
        before = json.loads(inner.read(f'election-archive/{edition}/extraction-{detail["previous_sha256"]}.json'))
        folder = ARCHIVE / edition
        found = read_symbol_detail(folder / before['source_file'], before['records'], set(detail['codes']))
        self.assertEqual(found[22]['votes_polled'], 211410)
        self.assertEqual(found[22]['source_page'], 107)

    def test_gujarat_2012_requires_percentage_and_elector_match(self):
        edition = GUJARAT2012
        bundle = zipfile.ZipFile(ROOT / 'exports' / (NAME + '.zip'))
        audit = json.loads(bundle.read('AUDIT.json'))
        detail = next(d for d in audit['editions'] if d['edition'] == edition)
        inner = zipfile.ZipFile(io.BytesIO(bundle.read(f'snapshot-{edition}.zip')))
        before = json.loads(inner.read(f'election-archive/{edition}/extraction-{detail["previous_sha256"]}.json'))
        folder = ARCHIVE / edition
        pages = json.loads((folder / before['ocr_file']).read_text(encoding='utf-8'))['pages']
        found = read_gujarat2012(pages, before['records'])
        self.assertEqual(found[4]['votes_polled'], 137376)
        self.assertEqual(found[42]['votes_polled'], 194988)
        self.assertEqual(found[79]['votes_polled'], 121079)
        self.assertEqual(found[15]['votes_polled'], 163569)
        self.assertEqual(found[99]['electors'], 181028)
        self.assertEqual(found[99]['votes_polled'], 121606)
        self.assertEqual(found[177]['votes_polled'], 203909)
        altered = json.loads(json.dumps(before['records']))
        altered[3]['electors'] += 1
        with self.assertRaisesRegex(ValueError, 'Gujarat OCR turnout checks differ'):
            read_gujarat2012(pages, altered)

    def test_bundle_preserves_candidates_and_existing_nonblank_totals(self):
        bundle = ROOT / 'exports' / (NAME + '.zip')
        self.assertEqual(hashlib.sha256(bundle.read_bytes()).hexdigest(),
                         bundle.with_suffix('.sha256').read_text(encoding='ascii').split()[0])
        with zipfile.ZipFile(bundle) as outer:
            audit = json.loads(outer.read('AUDIT.json'))
            self.assertEqual(sum(len(d['codes']) for d in audit['editions']), 57)
            for line in outer.read('SHA256SUMS').decode('ascii').splitlines():
                digest, name = line.split(None, 1)
                self.assertEqual(hashlib.sha256(outer.read(name)).hexdigest(), digest)
            for detail in audit['editions']:
                edition = detail['edition']
                snapshot = zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{edition}.zip')))
                correction = zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{edition}.zip')))
                old = json.loads(snapshot.read(f'election-archive/{edition}/extraction-{detail["previous_sha256"]}.json'))
                new = json.loads(correction.read(f'election-archive/{edition}/extraction.json'))
                manifest = json.loads(correction.read('manifest.json'))['files'][0]
                self.assertEqual(manifest['replaces_sha256'], detail['previous_sha256'])
                self.assertEqual(len(old['records']), len(new['records']))
                for before, after in zip(old['records'], new['records']):
                    self.assertEqual(before['candidates'], after['candidates'])
                    if before.get('votes_polled') not in (None, 0):
                        self.assertEqual(before['votes_polled'], after['votes_polled'])
                    if before.get('electors') not in (None, 0) and not (edition == GUJARAT2012 and before['code'] == 99):
                        self.assertEqual(before['electors'], after['electors'])


if __name__ == '__main__':
    unittest.main()
