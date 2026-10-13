import unittest
from build_electoral_village_links import code, norm, state_context


class OfficialCodeContextTest(unittest.TestCase):
    def test_official_numeric_excel_cells_preserve_code_identity(self):
        self.assertEqual('131757', code('131757.0'))
        self.assertEqual('', code(''))
        with self.assertRaises(ValueError):
            code('131757.5')

    def test_state_archive_context_treats_spaces_and_underscores_consistently(self):
        self.assertTrue(state_context('Uttar Pradesh', 'https://surveyofindia.gov.in/documents/UTTAR_PRADESH.zip', 'maps/agra-villages.geojson'))
        self.assertFalse(state_context('Uttar Pradesh', 'https://surveyofindia.gov.in/documents/KARNATAKA.zip', 'maps/bangalore-villages.geojson'))
        self.assertTrue(state_context('Uttar Pradesh', 'https://surveyofindia.gov.in/documents/State_District_Subdistrict_PAN%20INDIA.rar', 'maps/agra-villages.geojson'))
        self.assertEqual(norm('Uttar Pradesh'), norm('UTTAR_PRADESH'))


if __name__ == '__main__':
    unittest.main()
