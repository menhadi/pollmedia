import unittest
from civic_resource_guard import decision, trial_decision, quota_is_capped


class ResourceTests(unittest.TestCase):
    def test_collection_ignores_load_but_retains_memory_pressure_and_concurrency(self):
        self.assertTrue(decision(5000,0,30,6,0,0,collection=True)[0])
        self.assertFalse(decision(5000,0,30,6,0,0)[0])
        for args in [(3000,0,30,6,0,0),(5000,2,30,6,0,0),(5000,0,30,6,9,0),(5000,0,30,6,0,6)]:
            self.assertFalse(decision(*args,collection=True)[0])

    def test_trial_requires_enforced_cap_and_single_worker(self):
        args = [5000,0,8,6,0,0,True,35,1,15]
        self.assertTrue(trial_decision(*args)[0])
        for index, value in [(1,1),(6,False),(0,3000),(2,13),(4,9),(5,6),(7,19),(8,11),(9,31)]:
            blocked = args.copy(); blocked[index] = value
            self.assertFalse(trial_decision(*blocked)[0], (index,value))

    def test_quota_verification_rejects_unlimited_or_excessive_cpu(self):
        self.assertTrue(quota_is_capped('50000 100000'))
        for value in ['max 100000','100000 100000','0 100000']:
            self.assertFalse(quota_is_capped(value))

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
