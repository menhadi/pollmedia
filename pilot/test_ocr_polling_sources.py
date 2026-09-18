import unittest
from ocr_polling_sources import words_from_data


class OcrPreservationTest(unittest.TestCase):
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
