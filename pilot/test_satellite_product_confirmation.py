import unittest
from build_pilibhit_satellite import confirmed_product

class ProductConfirmationTest(unittest.TestCase):
    def test_other_satellite_on_same_day_does_not_reject_exact_product(self):
        selected = 'S2A_MSIL2A_20241109T053031_N0511_R105_T43RFM_20241109T084458'
        other = 'S2C_MSIL2A_20241109T054121_N0511_R105_T43RFM_20260703T204528'
        rows = [{'id': other}, {'id': selected}]
        self.assertEqual(confirmed_product(rows, selected + '.SAFE'), [rows[1]])

    def test_other_acquisition_cannot_substitute(self):
        selected = 'S2A_MSIL2A_20241109T053031_N0511_R105_T43RFM_20241109T084458'
        for alternative in [selected.replace('S2A', 'S2C'), selected.replace('T053031', 'T054121'), selected.replace('R105', 'R104'), selected.replace('T43RFM', 'T43RFN')]:
            with self.assertRaises(AssertionError):
                confirmed_product([{'id': alternative}], selected)

    def test_reprocessed_edition_confirms_same_acquisition(self):
        selected = 'S2B_MSIL2A_20231111T050029_N0509_R119_T44QND_20231111T100336.SAFE'
        official = {'id': 'S2B_MSIL2A_20231111T050029_N0510_R119_T44QND_20241111T220042'}
        self.assertEqual(confirmed_product([official], selected), [official])
