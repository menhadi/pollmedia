import json
import unittest
import build_ac_2006_retained_source_notes as b


class RetainedNotesTest(unittest.TestCase):
    def test_only_review_metadata_changes(self):
        changed = 0
        for eid, old, new, samples in b.revised_files():
            before, after = json.loads(old), json.loads(new)
            self.assertEqual(len(before['records']), len(after['records']))
            codes = {r['code'] for r in samples}
            for a, c in zip(before['records'], after['records'], strict=True):
                if a['code'] not in codes:
                    self.assertEqual(a, c)
                else:
                    changed += 1
                    for key in a.keys()-{'error'}:
                        self.assertEqual(a[key], c[key])
                    self.assertEqual(c['previous_review_note'], a['error'])
                    self.assertNotIn('source_warning_code', c)
                    self.assertNotIn('summary_result', c)
        self.assertEqual(changed, 18)


if __name__ == '__main__':
    unittest.main()
