import json
import unittest
import build_ac_2018_jayanagar_summary as b


class JayanagarSummaryTest(unittest.TestCase):
    def test_preserves_every_prior_record_and_keeps_june_identity(self):
        _, old, new, samples = b.revised_files()[0]
        before, after = json.loads(old), json.loads(new)
        self.assertEqual(before['records'], after['records'][:-1])
        self.assertEqual(len({r['code'] for r in after['records']}), 224)
        r = samples[0]
        self.assertEqual(r['candidates'], [])
        self.assertEqual(r['source_contested_candidates'], 19)
        self.assertEqual(r['poll_date'], '2018-06-11')
        self.assertIn('11-Jun-2018', r['name'])
        self.assertEqual(r['summary_result']['winner_votes']-r['summary_result']['runner_votes'], r['summary_result']['margin'])
        self.assertEqual(round(100*r['votes_polled']/r['electors'], 2), r['printed_turnout_percent'])


if __name__ == '__main__':
    unittest.main()
