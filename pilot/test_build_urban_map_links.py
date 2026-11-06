import json
import unittest
from build_urban_map_links import town_match


class UrbanIdentityTest(unittest.TestCase):
    def test_civic_designations_require_unique_code_exact_stem_and_compatible_status(self):
        raw={'State_LGD':'33','Dist_LGD':'610','Subdis_LGD':'05753N003','Vill_LGD':'803528','Vill_Cat':'Urban'}
        row={'id':44,'state_code':'33','district_code':'610','name':'Nambiyur (TP)',
             'geography':json.dumps({'Subdistt':'05753','Town/Village':'803528'})}
        source={'source_code':'803528','name':'NAMBIYUR'}
        records={('33','803528'):[row]}
        self.assertEqual(44,town_match(source,'33',records,raw)[0]['id'])
        for name in ['Nambiyur (M)', 'Nambiyur (CT)', 'Nambiyur (Part)', 'Nambiyur (TP + OG)', 'Nambiyur Ward 1', 'Nambiyoor']:
            self.assertIsNone(town_match(dict(source,name=name),'33',records,raw))
        self.assertIsNone(town_match(source,'33',records))
        self.assertIsNone(town_match(source,'33',records,dict(raw,Vill_Cat='Partly_Urban')))
        self.assertIsNone(town_match(source,'33',records,dict(raw,Vill_LGD='803528N001')))
        self.assertIsNone(town_match(source,'32',records,raw))
        conflicting={('33','803528'):[row,dict(row,district_code='611')]}
        self.assertIsNone(town_match(source,'33',conflicting,raw))
        corp=dict(row,name='Nambiyur (M Corp.)')
        self.assertEqual(44,town_match(dict(source,name='Nambiyur (M corporation)'),'33',{('33','803528'):[corp]},raw)[0]['id'])
        self.assertIsNone(town_match(source,'33',{('33','803528'):[dict(row,name='Nambiyur (TP) (Part)')]},raw))
        municipality=dict(row,name='Nambiyur (M)')
        self.assertEqual(44,town_match(source,'33',{('33','803528'):[municipality]},dict(raw,Vill_Cat='Fully Urban'))[0]['id'])
        self.assertIsNone(town_match(dict(source,name='Nambiyur (NP)'),'33',{('33','803528'):[municipality]},raw))

    def setUp(self):
        self.row = {'id':42,'level':'TOWN','residence':'Urban','state_code':'19','district_code':'342','name':'Official city (M Corp.)',
                    'geography':json.dumps({'Subdistt':'04500','Town/Village':'801742'})}
        self.records = {('19','801742'):[self.row]}
        self.source = {'source_code':'801742','name':'Original SOI spelling'}
        self.raw = {'State_LGD':'19','Dist_LGD':'342','Subdis_LGD':'04500','Vill_LGD':'801742'}

    def test_full_source_codes_resolve_different_names_without_editing_identities(self):
        row, basis = town_match(self.source,'19',self.records,self.raw)
        self.assertIs(self.row,row)
        self.assertIn('Full original SOI',basis)
        for field in ['State_LGD','Dist_LGD','Subdis_LGD','Vill_LGD']:
            self.assertIsNone(town_match(self.source,'19',self.records,dict(self.raw,**{field:'999999'})))
        self.assertEqual('801742',self.source['source_code'])

    def test_name_only_or_placeholder_codes_do_not_create_a_crosswalk(self):
        self.assertIsNone(town_match(self.source,'19',self.records))
        self.assertIsNone(town_match(dict(self.source,source_code='801742N001'),'19',self.records,self.raw))
        self.assertIsNone(town_match(self.source,'18',self.records,self.raw))
        self.assertIsNotNone(town_match(dict(self.source,name='OFFICIAL CITY (M Corp)'),'19',self.records))

    def test_duplicate_editions_share_identity_but_conflicting_geographies_require_full_codes(self):
        records={('19','801742'):[self.row,dict(self.row,id=43)]}
        self.assertEqual(42,town_match(self.source,'19',records,self.raw)[0]['id'])

    def test_exact_published_name_disambiguates_town_parts_without_combining_values(self):
        part=dict(self.row,id=99,name='Official city (M Corp. + OG) (Part)',geography=json.dumps({'Subdistt':'04501','Town/Village':'801742'}))
        records={('19','801742'):[part,self.row]}
        matched=town_match(dict(self.source,name=self.row['name']),'19',records)
        self.assertEqual(42,matched[0]['id'])
        self.assertEqual('04500',json.loads(matched[0]['geography'])['Subdistt'])

    def test_ct_designation_requires_archive_bound_urban_code_and_unique_identity(self):
        row=dict(self.row,name='Official city (CT)')
        records={('19','801742'):[row]}
        source=dict(self.source,name='Official city')
        raw=dict(self.raw,Dist_LGD='315',Subdis_LGD='current',Vill_Cat='Urban')
        self.assertEqual(42,town_match(source,'19',records,raw)[0]['id'])
        self.assertIsNone(town_match(source,'19',records))
        self.assertIsNone(town_match(source,'19',records,dict(raw,Vill_Cat='Rural')))
        self.assertEqual(42,town_match(source,'19',records,dict(raw,Vill_Cat='Fully_urban'))[0]['id'])
        self.assertIsNone(town_match(source,'19',records,dict(raw,Vill_Cat='Partly_urban')))
        self.assertIsNone(town_match(dict(source,name='Official city, Ward 1'),'19',records,dict(raw,Vill_Cat='Fully_urban')))
        self.assertIsNone(town_match(source,'19',records,dict(raw,Vill_LGD='0')))
        self.assertIsNone(town_match(source,'19',{('19','801742'):[dict(row,name='Official city (CT) (Part)')]},raw))
        self.assertIsNone(town_match(source,'19',{('19','801742'):[row,dict(row,district_code='343')]},raw))
        conflict=dict(self.row,id=99,district_code='343')
        records={('19','801742'):[self.row,conflict]}
        self.assertIsNone(town_match(dict(self.source,name=self.row['name']),'19',records))
        self.assertEqual(42,town_match(self.source,'19',records,self.raw)[0]['id'])


if __name__=='__main__':
    unittest.main()
