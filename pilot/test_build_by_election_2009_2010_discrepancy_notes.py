import copy
import json
import unittest

import build_by_election_2009_2010_discrepancy_notes as builder
from extract_assembly_modern import load_cells


class DiscrepancyNotesTest(unittest.TestCase):
    def test_original_values_survive_and_only_five_index_entries_change(self):
        old, new, originals, additions, reviews = builder.revised_files()
        for left, right in zip(json.loads(old)['records'], json.loads(new)['records'], strict=True):
            if left['id'] not in builder.TARGETS:
                self.assertEqual(left, right)
        self.assertEqual(len(reviews), 5)
        for review in reviews:
            prior = json.loads(originals[review['prior_path']])
            revised = json.loads(additions[review['path']])
            revised.pop('source_total_review')
            note = revised['notes'].pop()
            self.assertEqual(prior, revised)
            self.assertIn('no balancing votes', note)
            if revised['constituency'] == 'Siddipet':
                self.assertIn('exceeds printed votes polled by 17', note)

    def test_changed_printed_total_or_candidate_cell_is_refused(self):
        _, _, originals, _, _ = builder.revised_files()
        record = json.loads(next(iter(originals.values())))
        _, total, valid, polled = builder.TARGETS[record['id']]
        source = builder.ROOT / 'application/storage/app/private/election-by-elections' / record['edition'] / record['source_file']
        book = load_cells(source)
        try:
            rows = next(s.values for s in book if s.title == record['candidates'][0]['table'])
            for row, col in [(29,5),(28,5),(record['candidates'][0]['source_row'],2)]:
                changed = copy.deepcopy(rows)
                changed[row-1][col-1] = 'changed'
                with self.subTest(row=row), self.assertRaises(ValueError):
                    builder.verify_rows(record, changed, total, valid, polled)
        finally:
            book.close()


if __name__ == '__main__':
    unittest.main()
