import json
import unittest
from pathlib import Path
from extract_sir_voter_roll import serial_at


class PageRangeTest(unittest.TestCase):
    def test_section_break_keeps_every_printed_serial_once(self):
        context = json.loads((Path(__file__).parent/'sir_rollout'/'pilibhit-part-006.metadata.json').read_text(encoding='utf-8'))
        serials = [serial_at(context, int(page), cell)
                   for page, cards in context['page_cards'].items()
                   for cell in range(1, cards['count']+1)]
        self.assertEqual(serials, list(range(1, 688)))
        self.assertEqual(serial_at(context, 22, 9), 579)
        self.assertEqual(serial_at(context, 23, 1), 580)
        self.assertEqual(serial_at(context, 26, 18), 687)

    def test_existing_full_page_layout_keeps_original_serials(self):
        self.assertEqual(serial_at({'first_page': 3}, 4, 1), 31)


if __name__ == '__main__':
    unittest.main()
