import copy
import json
import unittest

from verify_mdds_map_links import qualify


class MddsMapLinksTest(unittest.TestCase):
    def setUp(self):
        self.source = dict(State_LGD='33', Dist_LGD='632', Subdis_LGD='05898N002',
                           Vill_LGD='644453', Vill_Cat='Rural', Vill_name='IKKARAIBOOLUVAMPATTI',
                           District='COIMBATORE', Sub_dist='PERUR')
        self.directory = [dict(lgd='644453', state='33', census2011='644453',
                               district='Coimbatore', subdistrict='Perur',
                               district_code='569', subdistrict_code='5957')]
        self.mdds = [dict(row=['33', '12', '0006', '00806300', 'Ikkaraibooluvampatti',
                               '33', '632', '05898', '644453', 'Ikkaraibooluvampatti'])]
        self.records = [dict(id=10, state_code='33', district_code='632',
                             geography=json.dumps({'Subdistt': '05898', 'Town/Village': '644453'}),
                             level='VILLAGE', residence='Rural', name='Ikkaraibooluvampatti')]

    def check(self):
        return qualify(self.source, self.directory, self.mdds, self.records)

    def test_non_numeric_source_parent_is_preserved_with_official_parent_names(self):
        original = copy.deepcopy(self.source)
        self.assertEqual(self.check()['census']['id'], 10)
        self.assertEqual(self.source, original)

    def test_conflicting_numeric_parent_is_not_ignored(self):
        self.source['Subdis_LGD'] = '12345'
        self.assertIsNone(self.check())

    def test_source_name_needs_documented_alias(self):
        self.source['Vill_name'] = 'Similar spelling'
        self.assertIsNone(self.check())

    def test_both_current_parent_names_are_required(self):
        self.source['Sub_dist'] = 'Another tehsil'
        self.assertIsNone(self.check())

    def test_non_unique_lgd_crosswalk_is_held(self):
        self.directory.append(dict(self.directory[0], lgd='900001'))
        self.assertIsNone(self.check())

    def test_census_parent_conflict_is_held(self):
        self.records[0]['district_code'] = '999'
        self.assertIsNone(self.check())

    def test_town_designation_requires_full_mdds_geography(self):
        self.records[0].update(level='TOWN', residence='Urban', name='Ikkaraibooluvampatti (CT)')
        self.assertIsNotNone(self.check())
        self.records[0]['name'] = 'Ikkaraibooluvampatti (Part) (CT)'
        self.assertIsNone(self.check())

    def test_urban_source_is_not_reclassified_as_a_village(self):
        self.source['Vill_Cat'] = 'Urban'
        self.assertIsNone(self.check())

    def test_conflicting_published_identities_are_held(self):
        self.records.append(dict(self.records[0], district_code='999'))
        self.assertIsNone(self.check())

    def administrative_evidence(self):
        return dict(status='issued', source_attributes=copy.deepcopy(self.source),
                    lgd=copy.deepcopy(self.directory[0]),
                    census_identity=['33', '632', '5898', '644453'],
                    whole_settlement=True, page_reviewed=True, sha256='a' * 64,
                    url='https://example.gov.in/order.pdf', page=20, schedule_row=4,
                    relation='scheduled_current_name', official_name=self.source['Vill_name'])

    def test_scheduled_name_requires_exact_record_and_issued_whole_settlement(self):
        self.source['Vill_name'] = 'Official alternate spelling'
        evidence = self.administrative_evidence()
        self.assertIsNotNone(qualify(self.source, self.directory, self.mdds, self.records, evidence))
        for field, value in [('status', 'proposed'), ('whole_settlement', False),
                             ('official_name', 'Another place'), ('page_reviewed', False),
                             ('census_identity', ['33', '632', '5898', '999999'])]:
            with self.subTest(field=field):
                changed = dict(evidence, **{field: value})
                self.assertIsNone(qualify(self.source, self.directory, self.mdds, self.records, changed))

    def test_coded_parent_evidence_does_not_bypass_name_or_code_guards(self):
        self.source['Subdis_LGD'] = '12345'
        evidence = dict(self.administrative_evidence(), relation='coded_current_parent',
                        official_settlement_code='644453')
        self.assertIsNotNone(qualify(self.source, self.directory, self.mdds, self.records, evidence))
        evidence['official_settlement_code'] = '999999'
        self.assertIsNone(qualify(self.source, self.directory, self.mdds, self.records, evidence))
        evidence['official_settlement_code'] = '644453'
        self.source['Vill_name'] = 'Other settlement'
        evidence['source_attributes'] = copy.deepcopy(self.source)
        self.assertIsNone(qualify(self.source, self.directory, self.mdds, self.records, evidence))


if __name__ == '__main__':
    unittest.main()
