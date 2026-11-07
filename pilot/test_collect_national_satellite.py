import unittest
from collect_national_satellite import choose_pair, should_attempt

def scene(date,platform='sentinel-2a',orbit='R062',bounds=(79,28,81,30),cloud=0):
    a,b,c,d=bounds
    return {'id':date,'geometry':{'type':'Polygon','coordinates':[[[a,b],[c,b],[c,d],[a,d],[a,b]]]},'properties':{'datetime':date+'T05:00:00Z','platform':platform,'s2:product_uri':'S2A_MSIL2A_date_N0509_'+orbit+'_T44RLS_date.SAFE','grid:code':'44RLS','eo:cloud_cover':cloud}}

class NationalSatelliteTest(unittest.TestCase):
    def test_recovery_never_reprocesses_completed_and_does_not_loop_failed_attempts(self):
        failed={'status':'failed'}
        self.assertFalse(should_attempt('done',{'done'},failed,True,{'status':'queued'}))
        self.assertTrue(should_attempt('pending',set(),failed,False,{'status':'queued'}))
        for status in ['attempting','failed','ready']:
            self.assertFalse(should_attempt('pending',set(),failed,False,{'status':status}))
        self.assertTrue(should_attempt('new',set(),{},False,{}))

    def test_different_orbit_sensor_season_or_incomplete_extent_is_rejected(self):
        first=[scene('2023-11-22')]
        for last in [scene('2024-11-26',orbit='R019'),scene('2024-11-26',platform='sentinel-2b'),scene('2024-11-01'),scene('2024-11-26',bounds=(79,28,79.5,30))]:
            with self.assertRaises(ValueError):choose_pair(first,[last],[79,28,81,30])

    def test_clearer_matched_pair_selected_without_altering_source_ids(self):
        before=scene('2023-11-22');after=scene('2024-11-26');cloudy=scene('2024-11-21',cloud=90)
        config,rows=choose_pair([before],[cloudy,after],[79,28,81,30])
        self.assertEqual(config['dates'],['2023-11-22','2024-11-26'])
        self.assertIs(rows[0][0],before);self.assertIs(rows[1][0],after)

if __name__=='__main__':unittest.main()
