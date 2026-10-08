import json
import unittest

import build_by_election_1997_identity_reviews as builder


class IdentityReviewTest(unittest.TestCase):
    def test_all_candidates_and_original_warnings_survive(self):
        old, new, originals, additions, reviews = builder.revised_files()
        targets = {r['id'] for r in reviews}
        self.assertEqual(len(targets), 16)
        for left, right in zip(json.loads(old)['records'], json.loads(new)['records'], strict=True):
            if left['id'] not in targets:
                self.assertEqual(left, right)
        count, printed_typo = 0, 0
        for review in reviews:
            prior = json.loads(originals[review['prior_path']])
            after = json.loads(additions[review['path']])
            for key in ['candidates', 'source_url', 'source_sha256', 'metadata_rows', 'status', 'year', 'edition', 'code', 'constituency']:
                self.assertEqual(prior[key], after[key])
            self.assertEqual(prior['notes'], after['notes'][:-1])
            self.assertEqual(after['original_identity']['state'], None)
            self.assertIn(after['state'], after['source_identity_heading'])
            self.assertNotIn('winner', after)
            count += len(after['candidates'])
            printed_typo += after['state'] == 'Uttat Pradesh'
        self.assertEqual(count, 132)
        self.assertEqual(printed_typo, 2)

    def test_removing_body_heading_cannot_fall_back_to_directory_or_title(self):
        _, _, originals, _, _ = builder.revised_files()
        record = json.loads(next(iter(originals.values())))
        source = builder.ROOT/'application/storage/app/private/election-by-elections'/record['edition']/record['source_file']
        body = source.read_bytes()
        changed = body.replace(b'Assemby', b'Unknown')
        self.assertNotEqual(body, changed)
        with self.assertRaises(ValueError):
            builder.verified_identity(changed, record)


if __name__ == '__main__':
    unittest.main()
