import copy
import json
import unittest

import build_by_election_historical_placeholder_reviews as builder


class HistoricalPlaceholderReviewsTest(unittest.TestCase):
    def test_only_dash_placeholders_change_and_all_original_evidence_survives(self):
        old, new, originals, additions, reviews = builder.revised_files()
        self.assertEqual(len(reviews), 3)
        ids = {r['id'] for r in reviews}
        for left, right in zip(json.loads(old)['records'], json.loads(new)['records'], strict=True):
            if left['id'] not in ids:
                self.assertEqual(left, right)
        for review in reviews:
            prior = json.loads(originals[review['prior_path']])
            after = json.loads(additions[review['path']])
            self.assertEqual(after['candidates'], [c for c in prior['candidates'] if c['name'] != '-'])
            self.assertEqual(after['notes'][:-1], prior['notes'])
            after['candidates'] = after.pop('original_candidate_rows')
            after['candidate_count'] = after.pop('original_candidate_count')
            after.pop('historical_placeholder_review')
            after['notes'].pop()
            self.assertEqual(after, prior)

    def test_source_mutation_and_named_candidate_discard_are_refused(self):
        _, _, originals, _, _ = builder.revised_files()
        record = json.loads(next(iter(originals.values())))
        source = builder.ROOT/'application/storage/app/private/election-by-elections'/record['edition']/record['source_file']
        body = source.read_bytes()
        prior, count = builder.TARGETS[record['id']]
        with self.assertRaises(ValueError):
            builder.reviewed_record(record, body+b'changed', prior, count)
        changed = copy.deepcopy(record)
        changed['candidates'][0]['name'] = '-'
        with self.assertRaises(ValueError):
            builder.reviewed_record(changed, body, prior, count)


if __name__ == '__main__':
    unittest.main()
