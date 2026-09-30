import unittest

from audit_election_coverage import polling_reference_summary


class PollingReferenceSummaryTest(unittest.TestCase):
    def test_duplicate_content_is_not_counted_as_distinct_election_coverage(self):
        sources = [
            {'sha256': 'a' * 64, 'file': 'one.pdf', 'polling_rows': 7, 'state': 'A'},
            {'sha256': 'a' * 64, 'file': 'one.pdf', 'polling_rows': 5, 'state': 'B'},
            {'sha256': 'b' * 64, 'file': 'two.xlsx', 'polling_rows': 2, 'state': 'B'},
        ]

        result = polling_reference_summary(sources)

        self.assertEqual(result['documents'], 3)
        self.assertEqual(result['distinct_file_contents'], 2)
        self.assertEqual(result['duplicate_file_references'], 1)
        self.assertEqual(result['pdf_references'], 2)
        self.assertEqual(result['distinct_pdf_contents'], 1)
        self.assertEqual(result['source_rows'], 14)
        self.assertEqual(result['source_rows_after_identical_file_dedup_min'], 7)
        self.assertEqual(result['source_rows_after_identical_file_dedup_max'], 9)
        self.assertEqual(result['files_with_different_extraction_counts'], 1)


if __name__ == '__main__':
    unittest.main()
