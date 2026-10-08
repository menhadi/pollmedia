import copy
import json
import unittest

import build_by_election_uncontested_source_notes as builder


class UncontestedNotesTest(unittest.TestCase):
    def test_only_source_notes_are_added_and_null_votes_survive(self):
        old, new, originals, additions, reviews = builder.revised_files()
        before, after = json.loads(old), json.loads(new)
        self.assertEqual(len(before['records']), len(after['records']))
        for left, right in zip(before['records'], after['records']):
            if left['id'] not in builder.TARGETS:
                self.assertEqual(left, right)
        for r in reviews:
            prior = json.loads(originals[r['prior_path']])
            revised = json.loads(additions[r['path']])
            review = revised.pop('uncontested_source_review')
            note = revised['notes'].pop()
            self.assertEqual(prior, revised)
            self.assertIsNone(revised['candidates'][0]['votes'])
            self.assertIn('not zero votes', note)
            self.assertEqual(review['source_cells'][3], 'Uncontested')
            self.assertNotIn('margin', revised)
            self.assertNotIn('votes_polled', revised)

    def test_single_candidate_without_explicit_label_is_not_enough(self):
        candidate = {'name': 'A', 'party': 'P', 'votes': None, 'raw_votes': 'Uncontested', 'source_row': 41, 'table': 'Sheet'}
        cells = [1, 'A', 'P', 'Uncontested']
        builder.verify_candidate(candidate, cells, 41, 'Sheet', 'A', 'P')
        for label in [None, '', 'Nil', 0, 'Contested']:
            changed = copy.deepcopy(cells)
            changed[3] = label
            with self.subTest(label=label), self.assertRaises(ValueError):
                builder.verify_candidate(candidate, changed, 41, 'Sheet', 'A', 'P')


if __name__ == '__main__':
    unittest.main()
