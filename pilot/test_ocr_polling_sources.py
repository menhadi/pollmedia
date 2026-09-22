import unittest
import json
from pathlib import Path
from tempfile import TemporaryDirectory
from unittest.mock import patch
from ocr_polling_sources import words_from_data, read_ocr_page, ocr_candidates, state_folders


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

    def test_only_pages_needing_ocr_are_selected(self):
        pages = [{'page': 1, 'notes': ['Scanned or empty page; OCR and visual checking are required.']},
                 {'page': 2, 'notes': ['Embedded text layer is unreliable; OCR and visual checking are required.']},
                 {'page': 3, 'notes': ['Source tables extracted; polling-row layout still requires mapping.']},
                 {'page': 4, 'notes': ['Page text was extracted, but no table grid was recognised; layout review or OCR is required.']},
                 {'page': 5, 'notes': []}]
        self.assertEqual([page['page'] for page in ocr_candidates(pages)], [1, 2])

    def test_requested_states_resolve_to_source_folders_without_every_manifest(self):
        with TemporaryDirectory() as directory:
            root = Path(directory)
            (root/'catalogue.json').write_text(json.dumps({'entries': [
                {'id': 'folder-a', 'state': 'BIHAR'}, {'id': 'folder-b', 'state': 'SIKKIM'}]}))
            self.assertEqual(state_folders(root, {'SIKKIM'}), {'folder-b'})
            self.assertEqual(state_folders(root, {'SIKKIM', 'BIHAR'}), {'folder-a', 'folder-b'})
            with self.assertRaisesRegex(ValueError, 'preserved source directory'):
                state_folders(root, {'KARNATAKA'})


if __name__=='__main__':
    unittest.main()
