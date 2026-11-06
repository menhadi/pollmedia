import unittest
from verify_soi_village_identities import verified_candidates, same_source_feature, source_fields


class QualifiedOfficialIdentityTest(unittest.TestCase):
    def setUp(self):
        self.source = {'OBJECTID': '10', 'State_LGD': '09', 'Dist_LGD': '135', 'Subdis_LGD': '00717', 'Vill_LGD': '114735', 'Vill_Cat': 'Rural', 'Vill_name': 'Old spelling', 'Sub_dist': 'Old tehsil', 'District': 'Old district'}
        self.village = {'lgd': '900001', 'state': '9', 'district_code': '200', 'subdistrict_code': '300', 'census2011': '114735', 'name': 'New spelling'}
        self.directory = {'114735': [self.village]}
        self.census = {('9', '114735'): [[42, 9, '09', '135', '00717', '114735', 'VILLAGE', 'Census spelling']]}

    def test_full_census_codes_resolve_spelling_changes_with_explicit_lgd_crosswalk(self):
        matches = verified_candidates(self.source, '9', self.directory, self.census)
        self.assertEqual(matches[0][0]['lgd'], '900001')
        self.assertIn('Full source Census', matches[0][1])
        for field in ['Dist_LGD', 'Subdis_LGD', 'State_LGD']:
            self.assertEqual([], verified_candidates(dict(self.source, **{field: '9999'}), '9', self.directory, self.census))
        self.assertEqual([], verified_candidates(dict(self.source, Subdis_LGD=''), '9', self.directory, self.census))
        self.assertEqual([], verified_candidates(dict(self.source, Vill_Cat='Urban'), '9', self.directory, self.census))
        for field in ['Vill_LGD', 'Dist_LGD', 'Subdis_LGD']:
            self.assertEqual([], verified_candidates(dict(self.source, **{field: self.source[field] + 'N001'}), '9', self.directory, self.census))

    def test_documented_schema_aliases_preserve_original_code_values(self):
        raw = {'OBJECTID_1': '0010', 'STATE_LGD': '09', 'Vill_cat': 'RURAL', 'Subdist': 'OLD TEHSIL', 'Villl_name':'SOURCE SPELLING'}
        fields = source_fields(raw)
        self.assertEqual('0010', fields['OBJECTID'])
        self.assertEqual('09', fields['State_LGD'])
        self.assertEqual('RURAL', fields['Vill_Cat'])
        self.assertEqual('OLD TEHSIL', fields['Sub_dist'])
        self.assertEqual('SOURCE SPELLING', fields['Vill_name'])
        with self.assertRaisesRegex(ValueError, 'Conflicting source field aliases'):
            source_fields(dict(raw, OBJECTID='999'))

    def test_current_lgd_hierarchy_requires_qualified_parent_codes(self):
        source = dict(self.source, Dist_LGD='200', Subdis_LGD='300')
        self.assertEqual('900001', verified_candidates(source, '9', self.directory, {})[0][0]['lgd'])
        self.assertEqual([], verified_candidates(dict(source, Subdis_LGD='301'), '9', self.directory, {}))

    def test_mixed_parent_code_editions_require_the_same_unique_official_village(self):
        for district,subdistrict in [('200','00717'),('135','300')]:
            source=dict(self.source,Dist_LGD=district,Subdis_LGD=subdistrict)
            match=verified_candidates(source,'9',self.directory,self.census)
            self.assertEqual('900001',match[0][0]['lgd'])
            self.assertIn('mixed current LGD and Census',match[0][1])
            self.assertEqual([],verified_candidates(dict(source,Dist_LGD='201'),'9',self.directory,self.census))
            self.assertEqual([],verified_candidates(source,'9',{'114735':[self.village,dict(self.village,lgd='900002')]},self.census))

    def test_conflicting_code_namespaces_and_split_village_crosswalks_are_withheld(self):
        another = dict(self.village, lgd='114735', census2011='123456', district_code='135', subdistrict_code='717')
        self.assertEqual([], verified_candidates(self.source, '9', {'114735': [self.village, another]}, self.census))
        self.assertEqual([], verified_candidates(self.source, '9', {'114735': [self.village, dict(self.village, lgd='900002')]}, self.census))
        conflict = self.census[('9', '114735')][0].copy()
        conflict[4] = '00718'
        self.assertEqual([], verified_candidates(self.source, '9', self.directory, {('9', '114735'): self.census[('9', '114735')] + [conflict]}))

    def test_lgd_village_code_with_historical_parents_requires_unique_crosswalk(self):
        directory = dict(self.directory, **{'900001': [self.village]})
        for district, subdistrict in [('135', '00717'), ('200', '00717'), ('135', '300')]:
            source = dict(self.source, Vill_LGD='900001', Dist_LGD=district, Subdis_LGD=subdistrict)
            self.assertEqual('900001', verified_candidates(source, '9', directory, self.census)[0][0]['lgd'])
            self.assertEqual([], verified_candidates(dict(source, Subdis_LGD='999'), '9', directory, self.census))
            self.assertEqual([], verified_candidates(source, '9', directory, {}))
            split = dict(directory, **{'114735': [self.village, dict(self.village, lgd='900002')]})
            self.assertEqual([], verified_candidates(source, '9', split, self.census))

    def test_original_feature_binding_uses_source_identifier_and_attributes(self):
        properties = {'source_code': '114735', 'name': 'Old spelling', 'source_subdistrict': 'Old tehsil'}
        layer = {'district': 'Old district'}
        self.assertTrue(same_source_feature(properties, '10', layer, self.source))
        self.assertFalse(same_source_feature(properties, '11', layer, self.source))
        self.assertFalse(same_source_feature(dict(properties, source_code='114736'), '10', layer, self.source))
        self.assertFalse(same_source_feature(dict(properties, name='Another source feature'), '10', layer, self.source))
        self.assertTrue(same_source_feature(properties, '0', layer, dict(self.source, OBJECTID='0')))
        self.assertFalse(same_source_feature(properties, 'invalid', layer, dict(self.source, OBJECTID='0')))


if __name__ == '__main__':
    unittest.main()
