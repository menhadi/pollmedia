import json
import unittest

import build_by_election_2000_2007_source_notes as builder


class SourceNotesTest(unittest.TestCase):
    def test_all_candidate_rows_and_warnings_remain_unchanged(self):
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
            self.assertEqual(prior, after)
        self.assertEqual(count, 27)

    def test_changed_printed_values_and_missing_uncontested_declaration_are_refused(self):
        _, _, originals, _, _ = builder.revised_files()
        for body in originals.values():
            record = json.loads(body)
            source = builder.ROOT/'application/storage/app/private/election-by-elections'/record['edition']/record['source_file']
            raw = source.read_bytes()
            target = next(t for t in builder.TARGETS if t['id'] == record['id'])
            changes = {'Tyui (ST)': (b'Declared Elected Uncontested', b'No declaration available'),
                       'Palamu': (b'42.65', b'4265'),
                       'Tosham': (b'12660', b'12661')}
            if record['constituency'] in changes:
                before, after = changes[record['constituency']]
                changed = raw.replace(before, after)
                self.assertNotEqual(raw, changed)
                with self.assertRaises(ValueError):
                    builder.verified_source(changed, record, target)


if __name__ == '__main__':
    unittest.main()
