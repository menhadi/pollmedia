import unittest
from extend_urban_census_scopes import scoped_records

def record(id=1,name='Example (M)',sub='00100',population=100):
    return {'id':id,'name':name,'state_code':'29','district_code':'572','geo':{'Town/Village':'800001','Ward':'0000','Subdistt':sub},'values':'{"TOT_P":'+str(population)+'}'}

class ScopeTests(unittest.TestCase):
    def test_repeated_identical_editions_are_deduplicated(self):
        self.assertEqual([2],[r['id'] for r in scoped_records([record(),record(id=2)])])
    def test_outgrowth_and_municipality_are_distinct(self):
        self.assertEqual(2,len(scoped_records([record(),record(id=2,name='Example (M + OG)',population=200)])))
    def test_parts_in_different_subdistricts_remain_separate(self):
        self.assertEqual(2,len(scoped_records([record(name='Example (Part)'),record(id=2,name='Example (Part)',sub='00200')])) )
    def test_conflicting_same_scope_is_withheld(self):
        self.assertEqual([],scoped_records([record(),record(id=2,population=200)]))

if __name__=='__main__':unittest.main()
