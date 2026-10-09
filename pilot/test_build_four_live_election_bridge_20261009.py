import json
import unittest

from build_four_live_election_bridge_20261009 import normalized, revised_files, SPECS
from build_pc_successful_candidate_reviews import digest


class LiveBridgeTests(unittest.TestCase):
    def test_only_record_order_and_serialization_are_ignored(self):
        first = {'records': [{'code': 2, 'votes': None}, {'code': 1, 'votes': 12}]}
        second = {'records': list(reversed(first['records']))}
        self.assertEqual(normalized(json.dumps(first)), normalized(json.dumps(second, indent=2)))
        second['records'][1]['votes'] = 0
        self.assertNotEqual(normalized('{"records":[{"code":2,"votes":null},{"code":1,"votes":12}]}'), normalized(json.dumps(second)))

    def test_duplicate_identity_is_rejected(self):
        with self.assertRaises(ValueError):
            normalized('{"records":[{"code":1},{"code":1}]}')

    def test_real_live_predecessors_and_candidates_are_preserved(self):
        revised = revised_files()
        self.assertEqual(len(revised), 4)
        self.assertEqual(sum(len(r[3]) for r in revised), 407)
        for (eid, old, new, samples), spec in zip(revised, SPECS):
            self.assertEqual(eid, spec[0])
            self.assertEqual(digest(old), spec[2])
            original = {r['code']: r for r in json.loads(old)['records']}
            updated = {r['code']: r for r in json.loads(new)['records']}
            self.assertEqual(original.keys(), updated.keys())
            for code in original:
                self.assertEqual(original[code]['candidates'], updated[code]['candidates'])


if __name__ == '__main__':
    unittest.main()
