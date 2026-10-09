import json
import unittest
import build_ac_1955_devi_wrapped_members as b


class DeviMembersTest(unittest.TestCase):
    def test_only_devi_review_changes(self):
        _, old, new, samples = b.revised_files()[0]
        before = json.loads(old)['records']; after = json.loads(new)['records']
        self.assertEqual(len(before), len(after))
        for a, c in zip(before, after, strict=True):
            if a['code'] != 80:
                self.assertEqual(a, c)
            else:
                self.assertEqual(a['candidates'], c['candidates'])
                for k in a.keys() - {'error'}:
                    self.assertEqual(a[k], c[k])
                self.assertEqual(a['error'], c['original_extraction_warning'])
        self.assertEqual(len(samples[0]['official_multi_seat_winners']), 2)
        self.assertEqual(samples[0]['official_multi_seat_winners'][0]['votes'], 61128)
        self.assertTrue(samples[0]['official_multi_seat_winners'][0]['name'].endswith('PRASAD BAHADUR GARU'))

    def test_changed_or_missing_wrapping_is_refused(self):
        for text in ['', 'Winner 1 INC MALLEPUDI RAJESWARA RAO YARLAGADDA SIVA RAMA61129\nPRASAD BAHADUR GARU']:
            with self.assertRaises(ValueError): b.unwrap(text)


if __name__ == '__main__':
    unittest.main()
