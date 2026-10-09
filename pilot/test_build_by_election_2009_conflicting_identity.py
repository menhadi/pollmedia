import json
import unittest

import build_by_election_2009_conflicting_identity as builder


class ConflictingIdentityTest(unittest.TestCase):
    def test_only_conflicting_identity_is_withheld_and_evidence_is_preserved(self):
        old, new, originals, additions, reviews = builder.revised_files()
        self.assertEqual(len(reviews), 1)
        for left, right in zip(json.loads(old)['records'], json.loads(new)['records'], strict=True):
            if left['id'] != builder.RID:
                self.assertEqual(left, right)
            else:
                self.assertIsNone(right['state'])
                self.assertIsNone(right['constituency'])
                self.assertEqual(right['candidate_count'], 4)
        prior = json.loads(next(iter(originals.values())))
        after = json.loads(next(iter(additions.values())))
        self.assertEqual(after['candidates'], prior['candidates'])
        self.assertIsNone(after['code'])
        self.assertEqual(after['status'], 'needs_review')
        after.update(after.pop('original_identity_fields'))
        after.pop('conflicting_identity_review')
        after['notes'].pop()
        self.assertEqual(after, prior)

    def test_changed_identity_evidence_is_refused(self):
        _, _, originals, _, _ = builder.revised_files()
        record = json.loads(next(iter(originals.values())))
        source = builder.ROOT/'application/storage/app/private/election-by-elections'/record['edition']/record['source_file']
        book = builder.load_cells(source)
        try:
            sheets = {s.title: list(s.values) for s in book if s.title in ['105bh', '111AC']}
        finally:
            book.close()
        sheets['111AC'][4][0] = 'Different official constituency'
        with self.assertRaises(ValueError):
            builder.reviewed_record(record, sheets)


if __name__ == '__main__':
    unittest.main()
