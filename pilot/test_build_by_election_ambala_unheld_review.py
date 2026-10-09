import copy
import json
import unittest

import build_by_election_ambala_unheld_review as builder


class AmbalaUnheldReviewTest(unittest.TestCase):
    def test_prior_evidence_and_unrelated_index_entries_are_preserved(self):
        old, new, originals, additions, reviews = builder.revised_files()
        self.assertEqual(len(reviews), 1)
        for left, right in zip(json.loads(old)['records'], json.loads(new)['records'], strict=True):
            if left['id'] != builder.RID:
                self.assertEqual(left, right)
        prior = json.loads(next(iter(originals.values())))
        after = json.loads(next(iter(additions.values())))
        self.assertEqual(after['candidates'], [])
        self.assertEqual(after['status'], 'needs_review')
        self.assertEqual(after['unheld_election_source_review']['source_statement'], 'Election not held.')
        self.assertEqual(after['unheld_election_source_review']['unassigned_source_rows'], prior['candidates'][1:])
        after['candidates'] = after.pop('original_candidate_rows')
        after['candidate_count'] = after.pop('original_candidate_count')
        after.pop('unheld_election_source_review')
        after['notes'].pop()
        self.assertEqual(after, prior)

    def test_missing_votes_alone_do_not_establish_an_unheld_election(self):
        _, _, originals, _, _ = builder.revised_files()
        prior = json.loads(next(iter(originals.values())))
        rows = [copy.deepcopy(c['source_cells']) for c in prior['candidates']]
        rows[0][6] = None
        with self.assertRaises(ValueError):
            builder.reviewed_record(prior, rows)
        rows[0][6] = 'Election not held.'
        rows[1][3] = 'Another constituency'
        with self.assertRaises(ValueError):
            builder.reviewed_record(prior, rows)


if __name__ == '__main__':
    unittest.main()
