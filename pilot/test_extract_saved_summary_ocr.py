"""Guard source-matched extraction from the preserved 2014 summary word boxes."""

import copy
import json
from pathlib import Path
import unittest

from extract_saved_summary_ocr import parse_arunachal_2014, read_gujarat_2012


FOLDER = Path(__file__).resolve().parents[1] / ('application/storage/app/private/election-archive/'
                                                 '08d56c7504299ea9043a1782')


class SavedSummaryOcrTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.pages = json.loads((FOLDER / 'ocr-words-v1.json').read_text(encoding='utf-8'))['pages']
        cls.records = json.loads((FOLDER / 'extraction.json').read_text(encoding='utf-8'))['records']

    def test_only_forty_nine_polled_summary_pages_pass(self):
        matched = [parse_arunachal_2014(self.pages[record['code'] + 11], record)
                   for record in self.records]
        self.assertEqual(sum(value is not None for value in matched), 49)
        self.assertEqual(matched[1]['electors'], 10082)
        self.assertEqual(matched[1]['votes_polled'], 7996)
        self.assertEqual(matched[1]['valid_candidate_votes'], 7788)
        self.assertIsNone(matched[2])  # Mukto printed 0.00% polling.

    def test_rejects_changed_identity_or_ocr_total(self):
        page = copy.deepcopy(self.pages[13])
        record = self.records[1]
        self.assertIsNotNone(parse_arunachal_2014(page, record))
        wrong = copy.deepcopy(record)
        wrong['name'] = 'DIFFERENT SEAT'
        self.assertIsNone(parse_arunachal_2014(page, wrong))
        for word in page['words']:
            if abs(word[1] - 386) <= 2.5 and word[4] == '7996':
                word[4] = '7995'
        self.assertIsNone(parse_arunachal_2014(page, record))


class GujaratDetailOcrTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        folder = FOLDER.parent / '503135d3e838d38c93d3bce7'
        cls.pages = json.loads((folder / 'ocr-words-v1.json').read_text(encoding='utf-8'))['pages']
        cls.records = json.loads((folder / 'extraction.json').read_text(encoding='utf-8'))['records']

    def test_only_isolated_turnout_totals_are_accepted(self):
        matched = read_gujarat_2012(self.pages, self.records)
        self.assertEqual(len(matched), 149)
        self.assertEqual(matched[1]['electors'], 195191)
        self.assertEqual(matched[1]['votes_polled'], 143451)
        self.assertEqual(matched[1]['general_votes'] + matched[1]['postal_votes'], 143451)
        self.assertNotIn(4, matched)  # OCR totals conflict for this seat.

    def test_rejects_changed_identity_or_turnout_sum(self):
        records = copy.deepcopy(self.records)
        records[0]['name'] = 'DIFFERENT SEAT'
        self.assertNotIn(1, read_gujarat_2012(self.pages, records))
        pages = copy.deepcopy(self.pages)
        pages[0]['text'] = pages[0]['text'].replace('142331 1120 143451 73.49',
                                                    '142331 1120 143452 73.49')
        self.assertNotIn(1, read_gujarat_2012(pages, self.records))


if __name__ == '__main__':
    unittest.main()
