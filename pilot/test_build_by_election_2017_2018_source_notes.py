import copy
import json
import unittest

import build_by_election_2017_2018_source_notes as builder


class WorkbookSourceNotesTest(unittest.TestCase):
    def test_candidates_warnings_and_other_records_remain_unchanged(self):
        old, new, originals, additions, reviews = builder.revised_files()
        self.assertEqual(len(reviews), 5)
        ids = {r['id'] for r in reviews}
        for left, right in zip(json.loads(old)['records'], json.loads(new)['records'], strict=True):
            if left['id'] not in ids:
                self.assertEqual(left, right)
        count = 0
        for review in reviews:
            prior = json.loads(originals[review['prior_path']])
            after = json.loads(additions[review['path']])
            count += len(prior['candidates'])
            after.pop('source_discrepancy_review')
            after['notes'].pop()
            self.assertEqual(after, prior)
        self.assertEqual(count, 47)

    def test_nil_zero_and_corrected_elector_digits_are_not_interchangeable(self):
        _, _, originals, _, _ = builder.revised_files()
        checked = 0
        for body in originals.values():
            record = json.loads(body)
            target = copy.deepcopy(next(t for t in builder.TARGETS if t['id'] == record['id']))
            if record['constituency'] == 'Palus Kadegaon':
                self.assertEqual(target['source_review_rows']['30'][5], 'Nil')
                target['source_review_rows']['30'][5] = 0
            elif record['constituency'].startswith('Bandhavgarh'):
                self.assertEqual(target['source_review_rows']['18'][3], 69956)
                target['source_review_rows']['18'][3] = 96956
            else:
                continue
            source = builder.ROOT/'application/storage/app/private/election-by-elections'/record['edition']/record['source_file']
            with self.assertRaises(ValueError):
                builder.verified_source(source.read_bytes(), record, target)
            checked += 1
        self.assertEqual(checked, 2)


if __name__ == '__main__':
    unittest.main()
