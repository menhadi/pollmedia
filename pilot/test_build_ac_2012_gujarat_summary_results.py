"""Source and preservation checks for the Gujarat 2012 summary revision."""

import hashlib
import io
import json
from pathlib import Path
import sys
import unittest
import zipfile

sys.path.insert(0, str(Path(__file__).resolve().parent))
from audit_ac_2012_gujarat_summary_results import EDITION, ROOT, audit, audit_refined
from build_ac_2012_gujarat_summary_results import NAME, NAME_V2, NAME_V3, NAME_V4, revised_edition


class Gujarat2012SummaryResultsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.old_body, cls.new_body, cls.detail = revised_edition()
        cls.old = json.loads(cls.old_body)
        cls.new = json.loads(cls.new_body)

    def test_official_source_acceptance_and_unresolved_seats(self):
        evidence = audit()
        self.assertEqual(171, evidence['coverage']['source_totals_verified'])
        self.assertEqual(140, evidence['coverage']['source_result_verified'])
        self.assertEqual([4, 32, 39, 40, 42, 44, 78, 99, 106, 126, 149],
                         self.detail['unresolved_codes'])
        self.assertEqual(182, len(evidence['rows']))
        self.assertEqual(140, sum(row['result'] is not None for row in evidence['rows']))

    def test_previous_bytes_and_detail_evidence_are_preserved(self):
        self.assertEqual(hashlib.sha256(self.old_body).hexdigest(), self.detail['previous_sha256'])
        self.assertEqual(182, len(self.new['records']))
        for before, after in zip(self.old['records'], self.new['records']):
            self.assertEqual(before['code'], after['code'])
            for field in ('name', 'candidates', 'turnout_totals', 'source_heading', 'detail_page',
                          'number_of_seats', 'status', 'original_extraction_warning'):
                self.assertEqual(before.get(field), after.get(field), (before['code'], field))
            self.assertEqual(before.get('source_url'), after.get('source_url'))
        self.assertEqual(self.old['records'][43], self.new['records'][43])  # Ellisbridge: OCR discrepancy held.

    def test_correct_turnout_is_voters_including_invalid_ballots(self):
        abdasa = self.new['records'][0]
        self.assertEqual(143507, abdasa['votes_polled'])
        self.assertEqual(143451, abdasa['summary_totals']['valid_candidate_votes'])
        self.assertEqual(60704, abdasa['summary_result']['winner_votes'])
        self.assertEqual(53091, abdasa['summary_result']['runner_votes'])
        self.assertEqual(7613, abdasa['summary_result']['margin'])
        self.assertEqual(22, abdasa['summary_page'])
        self.assertEqual('official_summary_turnout_only', abdasa['source_warning_code'])

    def test_data_only_bundle_is_checksum_verified_and_guarded(self):
        bundle = ROOT / 'exports' / (NAME + '.zip')
        expected, filename = bundle.with_suffix('.sha256').read_text(encoding='ascii').split()
        self.assertEqual(filename, bundle.name)
        self.assertEqual(expected, hashlib.sha256(bundle.read_bytes()).hexdigest())
        with zipfile.ZipFile(bundle) as outer:
            self.assertEqual(EDITION, outer.read('ARCHIVES').decode().strip())
            self.assertIn('flock -n', outer.read('IMPORT.sh').decode())
            self.assertIn('10485760', outer.read('IMPORT.sh').decode())
            self.assertIn('--allow-revision', outer.read('IMPORT.sh').decode())
            sums = [line.split() for line in outer.read('SHA256SUMS').decode().splitlines()]
            self.assertEqual(2, len(sums))
            for expected_sha, name in sums:
                self.assertEqual(expected_sha, hashlib.sha256(outer.read(name)).hexdigest())
            with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{EDITION}.zip'))) as inner:
                snapshot = f'election-archive/{EDITION}/extraction-{self.detail["previous_sha256"]}.json'
                self.assertEqual(self.old_body, inner.read(snapshot))
            with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
                self.assertEqual(self.new_body, inner.read(f'election-archive/{EDITION}/extraction.json'))


class Gujarat2012RefinedSummaryResultsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.old_body, cls.new_body, cls.detail = revised_edition(refined=True)
        cls.old = json.loads(cls.old_body)
        cls.new = json.loads(cls.new_body)

    def test_source_arithmetic_recovers_nine_more_turnouts(self):
        evidence = audit_refined()
        self.assertEqual(171, evidence['coverage']['source_totals_verified'])
        self.assertEqual(9, evidence['coverage']['source_totals_verified_by_arithmetic'])
        self.assertEqual(146, evidence['coverage']['source_result_verified'])
        self.assertEqual([39, 40], self.detail['unresolved_codes'])
        self.assertEqual([4, 32, 42, 44, 78, 99, 106, 126, 149],
                         [r['code'] for r in evidence['rows']
                          if r['totals_reason'] == 'source_totals_verified_by_arithmetic'])

    def test_derived_values_reconcile_two_source_equations(self):
        ellisbridge = self.new['records'][43]
        self.assertEqual(151222, ellisbridge['votes_polled'])
        self.assertEqual(151093, ellisbridge['summary_totals']['valid_candidate_votes'])
        mahuva = self.new['records'][98]
        self.assertEqual(3, mahuva['previous_detail_electors'])
        self.assertEqual(181028, mahuva['electors'])
        self.assertEqual(121740, mahuva['votes_polled'])
        self.assertEqual(195162, self.new['records'][41]['votes_polled'])
        self.assertEqual(self.old['records'][38], self.new['records'][38])
        self.assertEqual(self.old['records'][39], self.new['records'][39])

    def test_refined_bundle_starts_from_original_prior_checksum(self):
        path = ROOT / 'exports' / (NAME_V2 + '.zip')
        expected, filename = path.with_suffix('.sha256').read_text(encoding='ascii').split()
        self.assertEqual(path.name, filename)
        self.assertEqual(expected, hashlib.sha256(path.read_bytes()).hexdigest())
        with zipfile.ZipFile(path) as outer:
            audit_row = json.loads(outer.read('AUDIT.json'))
            self.assertEqual(self.detail['previous_sha256'], audit_row['previous_sha256'])
            self.assertEqual(self.detail['new_sha256'], audit_row['new_sha256'])
            self.assertIn('10485760', outer.read('IMPORT.sh').decode())
            with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{EDITION}.zip'))) as inner:
                old_path = f'election-archive/{EDITION}/extraction-{self.detail["previous_sha256"]}.json'
                self.assertEqual(self.old_body, inner.read(old_path))
            with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
                self.assertEqual(self.new_body, inner.read(f'election-archive/{EDITION}/extraction.json'))


class Gujarat2012ShiftedPageSummaryResultsTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.old_body, cls.new_body, cls.detail = revised_edition(refined=True, shifted_pages=True)
        cls.new = json.loads(cls.new_body)

    def test_all_official_summary_turnouts_have_evidence(self):
        evidence = audit_refined(shifted_pages=True)
        self.assertEqual(171, evidence['coverage']['source_totals_verified'])
        self.assertEqual(11, evidence['coverage']['source_totals_verified_by_arithmetic'])
        self.assertEqual(147, evidence['coverage']['source_result_verified'])
        self.assertEqual([], self.detail['unresolved_codes'])
        self.assertEqual(182, len(self.detail['changed']))
        self.assertEqual(161100, self.new['records'][38]['votes_polled'])
        self.assertEqual(160985, self.new['records'][38]['summary_totals']['valid_candidate_votes'])
        self.assertEqual(153891, self.new['records'][39]['votes_polled'])
        self.assertEqual(153772, self.new['records'][39]['summary_totals']['valid_candidate_votes'])

    def test_latest_bundle_is_guarded_from_same_prior_extraction(self):
        path = ROOT / 'exports' / (NAME_V3 + '.zip')
        expected, name = path.with_suffix('.sha256').read_text(encoding='ascii').split()
        self.assertEqual(name, path.name)
        self.assertEqual(expected, hashlib.sha256(path.read_bytes()).hexdigest())
        with zipfile.ZipFile(path) as outer:
            self.assertEqual(self.detail['previous_sha256'], json.loads(outer.read('AUDIT.json'))['previous_sha256'])
            script = outer.read('IMPORT.sh').decode()
            self.assertIn('flock -n', script)
            self.assertIn('10485760', script)
            self.assertIn('--allow-revision', script)
            with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{EDITION}.zip'))) as inner:
                old_path = f'election-archive/{EDITION}/extraction-{self.detail["previous_sha256"]}.json'
                self.assertEqual(self.old_body, inner.read(old_path))
            with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
                self.assertEqual(self.new_body, inner.read(f'election-archive/{EDITION}/extraction.json'))


class Gujarat2012NumericFallbackTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.old_body, cls.new_body, cls.detail = revised_edition(
            refined=True, shifted_pages=True, result_fallback=True)

    def test_five_additional_results_match_independent_source_arithmetic(self):
        base = audit_refined(shifted_pages=True)
        enhanced = audit_refined(shifted_pages=True, result_fallback=True)
        added = [later['code'] for earlier, later in zip(base['rows'], enhanced['rows'])
                 if earlier['result'] is None and later['result'] is not None]
        self.assertEqual([13, 80, 102, 108, 172], added)
        self.assertEqual(152, enhanced['coverage']['source_result_verified'])
        self.assertEqual(182, len(self.detail['changed']))
        self.assertEqual(28191, enhanced['rows'][79]['result']['margin'])

    def test_latest_bundle_uses_preserved_prior_bytes(self):
        path = ROOT / 'exports' / (NAME_V4 + '.zip')
        expected, name = path.with_suffix('.sha256').read_text(encoding='ascii').split()
        self.assertEqual(name, path.name)
        self.assertEqual(expected, hashlib.sha256(path.read_bytes()).hexdigest())
        with zipfile.ZipFile(path) as outer:
            self.assertEqual(self.detail['previous_sha256'], json.loads(outer.read('AUDIT.json'))['previous_sha256'])
            script = outer.read('IMPORT.sh').decode()
            self.assertIn('flock -n', script)
            self.assertIn('10485760', script)
            self.assertIn('--allow-revision', script)
            with zipfile.ZipFile(io.BytesIO(outer.read(f'snapshot-{EDITION}.zip'))) as inner:
                old_path = f'election-archive/{EDITION}/extraction-{self.detail["previous_sha256"]}.json'
                self.assertEqual(self.old_body, inner.read(old_path))
            with zipfile.ZipFile(io.BytesIO(outer.read(f'correction-{EDITION}.zip'))) as inner:
                self.assertEqual(self.new_body, inner.read(f'election-archive/{EDITION}/extraction.json'))


if __name__ == '__main__':
    unittest.main()
