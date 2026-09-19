import unittest
from unittest.mock import patch
from ocr_polling_sources import words_from_data, read_ocr_page


class OcrPreservationTest(unittest.TestCase):
    def test_timeout_retries_then_preserves_failure_without_stopping_next_page(self):
        with patch('ocr_polling_sources.pytesseract.image_to_data', side_effect=[RuntimeError('timeout'), RuntimeError('timeout'), {'text': []}]) as read:
            data, attempts, error = read_ocr_page(object(), 'eng+hin', '')
            self.assertEqual(words_from_data(data), [])
            self.assertEqual(attempts, ['timeout', 'timeout'])
            self.assertEqual(error, 'timeout')
            self.assertIsNone(read_ocr_page(object(), 'eng+hin', '')[2])
            self.assertEqual(read.call_count, 3)

    def test_successful_retry_keeps_attempt_evidence(self):
        with patch('ocr_polling_sources.pytesseract.image_to_data', side_effect=[RuntimeError('timeout'), {'text': ['0']} ]):
            data, attempts, error = read_ocr_page(object(), 'eng+hin', '')
        self.assertEqual(data['text'], ['0'])
        self.assertEqual(attempts, ['timeout'])
        self.assertIsNone(error)

    def test_ocr_keeps_zero_and_confidence_with_page_coordinates(self):
        words=words_from_data({'text':['','0','Name'],'conf':[-1,92.5,31],
                              'left':[0,14,90],'top':[0,33,33],'width':[0,8,40],'height':[0,15,15],
                              'block_num':[0,1,1],'par_num':[0,1,1],'line_num':[0,1,1]})
        self.assertEqual(len(words),2)
        self.assertEqual(words[0]['text'],'0')
        self.assertEqual((words[0]['left'],words[0]['top']),(14,33))
        self.assertEqual(words[1]['confidence'],31)


if __name__=='__main__':
    unittest.main()
