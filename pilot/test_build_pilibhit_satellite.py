import unittest
import numpy as np
from build_pilibhit_satellite import valid_pixels,paired_statistics

class SatelliteComparisonTest(unittest.TestCase):
    def test_cloud_shadow_and_unknown_pixels_are_excluded_with_edge_buffer(self):
        bands=np.full((5,9,9),.2);bands[4]=4
        bands[4,4,4]=9
        mask=valid_pixels(bands)
        self.assertFalse(mask[4,4]);self.assertFalse(mask[3,3]);self.assertTrue(mask[2,2])
        for classification in [0,1,2,3,7,8,9,10,11]:
            bands[4]=classification;self.assertFalse(valid_pixels(bands).any())

    def test_only_paired_pixels_enter_both_means(self):
        before=np.full((10,10),.3);after=np.full((10,10),.5)
        mask=np.ones((10,10),dtype=bool);mask[:2]=False
        before[:2]=.99;after[:2]=-.99
        result=paired_statistics(before,after,mask,100)
        self.assertEqual(result['ndvi_change'],.2)
        self.assertEqual(result['paired_coverage_pct'],80)
        mask[2]=False
        self.assertIsNone(paired_statistics(before,after,mask,100)['ndvi_change'])

    def test_tiny_polygons_and_empty_coverage_are_unavailable(self):
        values=np.zeros((4,4));mask=np.ones((4,4),dtype=bool)
        self.assertIsNone(paired_statistics(values,values,mask,16)['before_ndvi'])
        self.assertEqual(paired_statistics(values,values,mask&False,0)['paired_coverage_pct'],0)

if __name__=='__main__':unittest.main()
