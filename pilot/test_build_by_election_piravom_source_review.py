import copy
import json
import unittest

import build_by_election_piravom_source_review as builder
from extract_assembly_modern import load_cells


class PiravomReviewTest(unittest.TestCase):
    def test_only_target_index_entry_changes_and_original_rows_survive(self):
        old, new, originals, additions, reviews = builder.revised_files()
        before, after = json.loads(old), json.loads(new)
        self.assertEqual(len(before['records']), len(after['records']))
        for left, right in zip(before['records'], after['records']):
            if left['id'] != builder.RID:
                self.assertEqual(left, right)
        prior = json.loads(next(iter(originals.values())))
        revised = json.loads(next(iter(additions.values())))
        self.assertEqual(revised['original_candidate_rows'], prior['candidates'])
        self.assertEqual(revised['candidates'], [])
        self.assertEqual(revised['year'], 2012)
        self.assertEqual(revised['state'], 'Kerala')
        self.assertEqual(revised['reported_contested'], 9)
        self.assertEqual(revised['notes'][:len(prior['notes'])], prior['notes'])
        for key in ['source_url', 'source_sha256', 'raw_table_file', 'metadata_rows', 'status', 'id', 'edition']:
            self.assertEqual(prior[key], revised[key])
        self.assertNotIn('winner', revised)
        self.assertNotIn('margin', revised)
        self.assertEqual(reviews[0]['prior_sha256'], builder.PRIOR_SHA)

    def test_changed_source_identity_dates_or_candidate_slots_are_refused(self):
        source = builder.ROOT / 'application/storage/app/private/election-by-elections' / builder.EDITION / (builder.SOURCE_SHA + '.xls')
        book = load_cells(source)
        try:
            rows = next(s.values for s in book if s.title == 'Sheet1')
            builder.verified_sheet(rows)
            for row, col, replacement in [(2,1,'BYE- ELECTION- 2009'),(3,3,'OTHER STATE'),
                                           (23,1,'18-3-2012'),(41,2,'Actual candidate'),(13,5,8)]:
                changed = copy.deepcopy(rows)
                changed[row-1][col-1] = replacement
                with self.subTest(row=row, col=col), self.assertRaises(ValueError):
                    builder.verified_sheet(changed)
        finally:
            book.close()


if __name__ == '__main__':
    unittest.main()
