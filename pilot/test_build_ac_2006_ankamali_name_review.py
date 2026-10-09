import json
import unittest
import build_ac_2006_ankamali_name_review as b


class AnkamaliReviewTest(unittest.TestCase):
    def test_review_preserves_all_source_values_and_candidates(self):
        _, old, new, samples = b.revised_files()[0]
        before, after = json.loads(old), json.loads(new)
        self.assertEqual(len(before['records']), len(after['records']))
        for a, c in zip(before['records'], after['records'], strict=True):
            if a['code'] != 68:
                self.assertEqual(a, c)
            else:
                for k in a.keys() - {'error'}:
                    self.assertEqual(a[k], c[k])
                self.assertEqual(a['error'], c['previous_review_note'])
        self.assertNotIn('source_warning_code', samples[0])
        self.assertEqual(samples[0]['official_summary_name_review']['winner'], 'JOSE THETTAYIL')
        self.assertEqual(samples[0]['candidates'][0]['candidate_name'], '. JOSE THETTAYIL')


if __name__ == '__main__':
    unittest.main()
