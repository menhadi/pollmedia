import unittest
from civic_resource_guard import decision


class ResourceTests(unittest.TestCase):
    def test_single_worker_can_use_lower_ram_band(self):
        self.assertTrue(decision(3500,0,3,6,0,0)[0])
        self.assertFalse(decision(3500,1,3,6,0,0)[0])

    def test_second_worker_needs_headroom_and_limit_is_two(self):
        self.assertTrue(decision(5000,1,3,6,0,0)[0])
        self.assertFalse(decision(8000,2,3,6,0,0)[0])

    def test_pressure_and_load_block_admission(self):
        for args in [(5000,0,7,6,0,0),(5000,0,3,6,9,0),(5000,0,3,6,0,6),(3000,0,3,6,0,0)]:
            self.assertFalse(decision(*args)[0])


if __name__ == '__main__': unittest.main()
