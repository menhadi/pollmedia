import copy
import hashlib
import json
from pathlib import Path
import tempfile
import unittest

from build_original_education_package import URBAN, RURAL, PINS, map_group, definitions, load_reviewed, reconcile_geography


def panel(residence='Urban'):
    scheme = URBAN if residence == 'Urban' else RURAL
    result = []
    for index, (label, _) in enumerate(scheme):
        # Zero is a reported value, distinct from the original ellipsis.
        item = dict(original_geography='TRIPURA', residence=residence,
                    physical_page=168, printed_page=164, source_columns=dict(P=2, M=3, F=4),
                    values=dict(P=0, M=0, F=0), parent_educational_level=None)
        if residence == 'Urban':
            item.update(original_educational_level=label, source_row_sequence=index + 1)
            if index >= 9:
                item['parent_educational_level'] = URBAN[8][0]
        else:
            item.update(educational_level_original=label, original_row_ordinal=index)
        result.append(item)
    return result


class OriginalEducationMappingTest(unittest.TestCase):
    def test_ellipsis_stays_null_and_prevents_strict_sum(self):
        cells = panel()
        cells[9]['values']['F'] = None
        cells[9]['missing_cell_notation'] = dict(F='...')
        row, checks = map_group(cells, 'Urban', 'TRIPURA', 168)
        self.assertIsNone(row['values']['ED_A_ENGINEERING_F'])
        self.assertEqual(row['values']['ED_A_MEDICINE_F'], 0)
        self.assertEqual(row['value_evidence']['ED_A_ENGINEERING_F']['missing_cell_notation'], '...')
        statuses = {c['check']: c['status'] for c in checks}
        self.assertEqual(statuses['ED_A_ENGINEERING_P=M+F'], 'skipped_missing_source_cell')
        self.assertEqual(statuses['ED_A_TECH_DEGREE_F=branches'], 'skipped_missing_source_cell')

    def test_subgroups_are_not_added_again_to_population(self):
        cells = panel()
        cells[0]['values'] = cells[8]['values'] = cells[9]['values'] = dict(P=7, M=5, F=2)
        row, checks = map_group(cells, 'Urban', 'TRIPURA', 168)
        self.assertTrue(all(c['status'] == 'passed' for c in checks))
        self.assertEqual(definitions()['ED_A_ENGINEERING_P']['parent_field'], 'ED_A_TECH_DEGREE_P')
        self.assertFalse(definitions()['ED_A_ENGINEERING_P']['additive_to_total'])
        self.assertEqual(row['values']['TOT_P'], 7)

    def test_discrepancy_preserves_reported_value_and_explicit_note(self):
        cells = panel()
        cells[0]['values'] = dict(P=3, M=1, F=1)
        row, checks = map_group(cells, 'Urban', 'TRIPURA', 168)
        self.assertEqual(row['values']['TOT_P'], 3)
        self.assertTrue(any(c['status'] == 'source_discrepancy' for c in checks))
        self.assertTrue(any('Source discrepancy: TOT_P=M+F' in f for f in row['flags']))

    def test_types_null_without_notation_and_source_order_rejected(self):
        for value in [True, '0', -1, 0.0, None]:
            cells = panel()
            cells[1]['values']['P'] = value
            with self.subTest(value=value), self.assertRaises(ValueError):
                map_group(cells, 'Urban', 'TRIPURA', 168)
        for mutate in [lambda c: c.pop(), lambda c: c.append(copy.deepcopy(c[-1])),
                       lambda c: c[1].update(original_educational_level='Unknown category'),
                       lambda c: c[9].update(parent_educational_level=None),
                       lambda c: c[2].update(physical_page=170),
                       lambda c: c[1].update(source_row_sequence=1)]:
            cells = panel()
            mutate(cells)
            with self.assertRaises(ValueError):
                map_group(cells, 'Urban', 'TRIPURA', 168)

    def test_residence_schemes_and_source_identities_separate(self):
        urban, _ = map_group(panel(), 'Urban', 'TRIPURA', 168)
        rural, _ = map_group(panel('Rural'), 'Rural', 'TRIPURA', 168)
        self.assertNotEqual(urban['record_key'], rural['record_key'])
        self.assertIn('ED_A_MATRIC_HIGHER_SECONDARY_P', urban['values'])
        self.assertIn('ED_B_MATRIC_AND_ABOVE_P', rural['values'])
        self.assertNotIn('ED_A_MATRIC_HIGHER_SECONDARY_P', rural['values'])
        self.assertNotIn('P_LIT', urban['values'])
        self.assertEqual(urban['record_key'], hashlib.sha256(urban['source_record_identity'].encode()).hexdigest())
        self.assertEqual(len(definitions()), 60)

    def test_changed_candidate_cannot_be_reapproved_by_new_package_hash(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            name = next(iter(PINS))
            (root / name).write_text(json.dumps({'reported_population': 999}))
            with self.assertRaisesRegex(ValueError, 'Pinned reviewed candidate checksum differs'):
                load_reviewed(root)

    def test_geography_reconciliation_never_infers_unreported_value(self):
        rows = []
        for residence in ['Urban', 'Rural']:
            rows.extend([dict(residence=residence, original_name='TRIPURA', source_record_identity=residence,
                              values=dict(TOT_P=8, TOT_F=0), flags=[]),
                         dict(residence=residence, original_name='source child', values=dict(TOT_P=7, TOT_F=None))])
        checks = reconcile_geography(rows)
        self.assertEqual(rows[0]['values']['TOT_P'], 8)
        self.assertTrue(any('Source discrepancy' in f for f in rows[0]['flags']))
        self.assertEqual(sum(c['status'] == 'source_discrepancy' for c in checks), 2)
        self.assertEqual(sum(c['status'] == 'skipped_missing_source_cell' for c in checks), 2)


if __name__ == '__main__':
    unittest.main()
