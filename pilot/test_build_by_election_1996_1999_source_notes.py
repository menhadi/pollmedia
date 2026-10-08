import json
import unittest

import build_by_election_1996_1999_source_notes as builder


class SourceNotesTest(unittest.TestCase):
    def test_original_records_remain_unchanged_except_added_notes(self):
        old, new, originals, additions, reviews = builder.revised_files()
        self.assertEqual(len(reviews), 7)
        ids = {r['id'] for r in reviews}
        for left, right in zip(json.loads(old)['records'], json.loads(new)['records'], strict=True):
            if left['id'] not in ids:
                self.assertEqual(left, right)
        for review in reviews:
            prior = json.loads(originals[review['prior_path']])
            after = json.loads(additions[review['path']])
            after.pop('source_discrepancy_review')
            after['notes'].pop()
            self.assertEqual(prior, after)

    def test_zero_vote_alone_does_not_establish_unopposed_result(self):
        _, _, originals, _, _ = builder.revised_files()
        record = next(json.loads(b) for b in originals.values() if json.loads(b)['constituency'] == 'Basra(ST)')
        source = builder.ROOT/'application/storage/app/private/election-by-elections'/record['edition']/record['source_file']
        body = source.read_bytes()
        changed = body.replace(b'ELECTED UNOPPOSED', b'DECLARATION ABSENT')
        self.assertNotEqual(body, changed)
        target = next(t for t in builder.TARGETS if t['id'] == record['id'])
        with self.assertRaises(ValueError):
            builder.verified_source(changed, record, target)


if __name__ == '__main__':
    unittest.main()
