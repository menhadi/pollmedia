import json
import unittest

import build_by_election_2002_html_recovery as builder


class HtmlRecoveryTest(unittest.TestCase):
    def test_recovery_preserves_original_rows_and_other_index_entries(self):
        old, new, originals, additions, reviews = builder.revised_files()
        for left, right in zip(json.loads(old)['records'], json.loads(new)['records'], strict=True):
            if left['id'] not in builder.TARGETS:
                self.assertEqual(left, right)
        recovered = 0
        for review in reviews:
            prior = json.loads(originals[review['prior_path']])
            after = json.loads(additions[review['path']])
            self.assertEqual(prior['candidates'], after['original_candidate_rows'])
            self.assertEqual(prior['notes'], after['notes'][:-1])
            self.assertEqual(len(after['candidates']), prior['reported_contested'])
            self.assertEqual(sum(c['votes'] for c in after['candidates']), after['html_source_review']['valid_candidate_votes'])
            for key in ['source_url', 'source_sha256', 'metadata_rows', 'status', 'year', 'edition', 'kind', 'state', 'constituency']:
                self.assertEqual(prior[key], after[key])
            self.assertNotIn('winner', after)
            recovered += len(after['candidates'])
        self.assertEqual(recovered, 39)

    def test_changed_vote_count_or_totals_are_refused(self):
        _, _, originals, _, _ = builder.revised_files()
        prior = json.loads(next(iter(originals.values())))
        source = builder.ROOT/'application/storage/app/private/election-by-elections'/prior['edition']/prior['source_file']
        body = source.read_bytes()
        _, _, count, electors, polled, valid = builder.TARGETS[prior['id']]
        for values in [(count-1,electors,polled,valid),(count,electors+1,polled,valid),
                       (count,electors,polled+1,valid),(count,electors,polled,valid+1)]:
            with self.subTest(values=values), self.assertRaises(ValueError):
                builder.parse_source(body, *values)
        changed = body.replace(b'256360', b'256361')
        self.assertNotEqual(body, changed)
        with self.assertRaises(ValueError):
            builder.parse_source(changed, count, electors, polled, valid)


if __name__ == '__main__':
    unittest.main()
