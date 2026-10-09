import json
import unittest
from unittest.mock import patch

import build_pc_successful_candidate_reviews as builder
from build_pc_1967_1971_successful_candidate_reviews import SPECS as MORE_SPECS


class SuccessfulCandidateReviewsTest(unittest.TestCase):
    def test_only_review_fields_change_and_source_bytes_are_retained(self):
        count = 0
        for eid, old, new, samples in builder.revised_files(specs=builder.SPECS + MORE_SPECS):
            codes = {r['code'] for r in samples}
            for before, after in zip(json.loads(old)['records'], json.loads(new)['records'], strict=True):
                if before['code'] in codes:
                    count += 1
                    self.assertEqual(before['candidates'], after['candidates'])
                    after['error'] = after.pop('original_extraction_warning')
                    for key in ['source_warning_code', 'official_successful_candidate', 'official_source_url']:
                        after.pop(key)
                self.assertEqual(before, after)
        self.assertEqual(count, 10)

    def test_missing_official_list_is_refused(self):
        class Page:
            def get_text(self):
                return 'No declaration'
        class Document:
            def __enter__(self):
                return self
            def __exit__(self, *args):
                pass
            def __getitem__(self, index):
                return Page()
        with patch.object(builder.fitz, 'open', return_value=Document()):
            with self.assertRaises(ValueError):
                builder.revised_files()


if __name__ == '__main__':
    unittest.main()
