import unittest
from build_electoral_village_links import code, norm, state_context
from build_village_display_links import candidates, census_candidates, normalize_name


class OfficialCodeContextTest(unittest.TestCase):
    def test_display_link_ignores_only_terminal_directory_numbers(self):
        self.assertEqual(normalize_name('Rahmana (122)'), normalize_name('RAHMANA'))
        self.assertNotEqual(normalize_name('Rahmana (SC)'), normalize_name('RAHMANA'))
        row = {'lgd': '700001', 'state': '6', 'name': 'Rahmana (122)', 'subdistrict': 'Sonipat'}
        source = {'source_code': '059775', 'name': 'RAHMANA', 'subdistrict': 'Sonipat'}
        self.assertEqual([row], candidates(source, '6', {'59775': [row]}))
        self.assertEqual([], candidates(source, '9', {'59775': [row]}))
        self.assertEqual([], candidates(dict(source, subdistrict='Different'), '6', {'59775': [row]}))
        self.assertEqual([], candidates(dict(source, status='ambiguous'), '6', {'59775': [row]}))

    def test_census_crosswalk_retains_current_lgd_identity_after_names_change(self):
        row = {'lgd': '700001', 'state': '6', 'name': 'New village name', 'subdistrict': 'New tehsil', 'census2011': '059775'}
        source = {'source_code': '059775', 'name': 'Historical village', 'subdistrict': 'Historical tehsil'}
        official = [42, 9, '06', '131', '00100', '059775', 'VILLAGE', 'Historical village']
        inventory = {('06', '59775'): [official]}
        directory = {'59775': [row]}
        self.assertEqual([row], census_candidates(source, '6', directory, inventory))
        self.assertEqual([], census_candidates(dict(source, name='Another village'), '6', directory, inventory))
        self.assertEqual([], census_candidates(dict(source, status='ambiguous'), '6', directory, inventory))
        self.assertEqual([], census_candidates(source, '9', directory, inventory))
        self.assertEqual([], census_candidates(source, '6', {'59775': [row, dict(row, lgd='700002')]}, inventory))
        self.assertEqual([], census_candidates(source, '6', directory, {('06', '59775'): [official, official[:4] + ['00200'] + official[5:]]}))
        self.assertEqual([], census_candidates(source, '6', {'59775': [dict(row, census2011='123456')]}, inventory))

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
