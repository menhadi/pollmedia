import unittest
from shapely.geometry import Polygon, LineString, GeometryCollection
from build_india_admin_display import outline, polygonal

class DisplayOutlineTest(unittest.TestCase):
    def test_outline_leaves_original_polygons_unchanged(self):
        original=Polygon([(0,0),(1,0),(1,1),(0,1),(0,0)])
        before=original.wkb
        display=outline([original,Polygon([(1,0),(2,0),(2,1),(1,1),(1,0)])])
        self.assertEqual(before,original.wkb)
        self.assertAlmostEqual(2,display.area)
        self.assertTrue(display.is_valid)

    def test_non_area_repair_artifacts_are_not_sent_to_the_map(self):
        square=Polygon([(0,0),(1,0),(1,1),(0,1),(0,0)])
        repaired=polygonal(GeometryCollection([square,LineString([(2,2),(3,3)])]))
        self.assertEqual('Polygon',repaired.geom_type)
        self.assertTrue(repaired.equals(square))
        self.assertIsNone(outline([]))

if __name__=='__main__':unittest.main()
