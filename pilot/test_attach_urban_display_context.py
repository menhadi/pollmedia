import unittest
from shapely.geometry import box,mapping
from attach_urban_display_context import context


class UrbanDisplayContextTest(unittest.TestCase):
    def test_only_full_unique_coverage_selects_a_display_district(self):
        districts=[(('19','315'),box(0,0,2,2)),(('19','316'),box(2,0,4,2))]
        self.assertEqual([('19','315')],context(mapping(box(.1,.1,1,1)),districts))
        self.assertEqual([],context(mapping(box(1,1,3,2)),districts))
        self.assertEqual([],context(mapping(box(5,5,6,6)),districts))
        self.assertEqual([],context(mapping(box(.1,.1,1,1)),districts+[(('19','999'),box(0,0,2,2))]))


if __name__=='__main__':
    unittest.main()
