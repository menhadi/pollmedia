import unittest
from link_urban_parent_census import parent_candidates
class ParentMatchingTests(unittest.TestCase):
 def setUp(self):
  self.b={'localBodyNameEnglish':'Example','stateCode':28}
  self.p={'state_census2011_code':'28','district_census2011_code':'551','subdistrict_code':5223,'subdistrict_census2011_code':'05223'}
  self.r={'id':1,'state_code':'28','district_code':'551','name':'Example (CT)','geo':{'Subdistt':'05223','Town/Village':'593289','Ward':'0000'},'values':'{}'}
 def test_exact_parent(self):self.assertEqual(len(parent_candidates(self.b,[self.p],{('28','551','example'):[self.r]})),1)
 def test_wrong_subdistrict(self):
  self.p['subdistrict_census2011_code']='05224';self.assertEqual(parent_candidates(self.b,[self.p],{('28','551','example'):[self.r]}),[])
 def test_cantonment_not_assumed(self):
  self.r['name']='Example (CB)';self.assertEqual(parent_candidates(self.b,[self.p],{('28','551','example'):[self.r]}),[])
 def test_multiple_towns_rejected(self):
  other={**self.r,'id':2,'geo':{**self.r['geo'],'Town/Village':'593290'}};self.assertEqual(parent_candidates(self.b,[self.p],{('28','551','example'):[self.r,other]}),[])
if __name__=='__main__':unittest.main()
