import json
import unittest
from unittest.mock import patch

import build_pc_1951_rayagada_uncontested_review as builder


class RayagadaUncontestedReviewTest(unittest.TestCase):
    def test_only_review_fields_change_and_source_bytes_are_retained(self):
        count = 0
        for eid, old, new, samples in builder.revised_files():
            codes = {r['code'] for r in samples}
            for before, after in zip(json.loads(old)['records'], json.loads(new)['records'], strict=True):
                if before['code'] in codes:
                    count += 1
                    self.assertEqual(before['candidates'], after['candidates'])
                    after['error'] = after.pop('original_extraction_warning')
                    for key in ['source_warning_code', 'summary_source_file', 'summary_source_sha256', 'official_source_url']:
                        after.pop(key)
                self.assertEqual(before, after)
        self.assertEqual(count, 1)

    def test_missing_official_list_is_refused(self):
        class Page:
            def get_text(self, **kwargs):
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
