import json
import unittest
import fitz
import build_ac_1954_1955_multi_seat_members as b


class MemberReviewTests(unittest.TestCase):
    def test_only_source_review_fields_change(self):
        allowed = {'original_extraction_warning', 'error', 'source_warning_code', 'summary_page', 'summary_totals', 'summary_source_file', 'summary_source_sha256', 'official_source_url', 'official_summary_constituency_name', 'official_multi_seat_winners'}
        count = 0
        for eid, old, new, samples in b.revised_files():
            codes = {r['code'] for r in samples}
            for before, after in zip(json.loads(old)['records'], json.loads(new)['records'], strict=True):
                self.assertEqual(before['candidates'], after['candidates'])
                changes = {k for k in before.keys() | after.keys() if before.get(k) != after.get(k)}
                if before['code'] in codes:
                    self.assertTrue(changes <= allowed)
                    self.assertEqual(after['original_extraction_warning'], before['error'])
                    self.assertEqual(len(after['official_multi_seat_winners']), 2)
                    count += 1
                else:
                    self.assertEqual(before, after)
            if eid == b.SPECS[0][0]:
                self.assertNotIn(80, codes)
        self.assertEqual(count, 51)

    def test_source_vote_or_candidate_changes_are_rejected(self):
        eid, old, _, samples = b.revised_files()[1]
        record = next(r for r in json.loads(old)['records'] if r['code'] == samples[0]['code'])
        with fitz.open(b.shared.ROOT/'application/storage/app/private/election-archive'/eid/samples[0]['summary_source_file']) as pdf:
            text = pdf[samples[0]['summary_page']-1].get_text(sort=True)
        b.declarations(text, record)
        bad = json.loads(json.dumps(record)); bad['votes_polled'] += 1
        with self.assertRaises(ValueError): b.declarations(text, bad)
        bad = json.loads(json.dumps(record)); bad['candidates'][0]['candidate_name'] = 'UNVERIFIED'
        with self.assertRaises(ValueError): b.declarations(text, bad)


if __name__ == '__main__':
    unittest.main()
