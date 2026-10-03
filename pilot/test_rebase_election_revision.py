"""Protect live election corrections against stale package replacements."""

import json
import unittest

from pilot.rebase_election_revision import rebase


def body(records):
    return json.dumps({'kind': 'ac', 'year': 1991, 'source_url': 'official',
                       'source_file': 'official.pdf', 'source_sha256': 'hash',
                       'records': records}).encode()


class RebaseElectionRevisionTests(unittest.TestCase):
    def test_disjoint_live_turnout_and_proposed_result_both_survive(self):
        old = body([{'code': 131, 'votes': None}, {'code': 151, 'winner': None, 'candidates': [1, 2]}])
        proposed = body([{'code': 131, 'votes': None}, {'code': 151, 'winner': 'A', 'candidates': [1, 2]}])
        live = body([{'code': 131, 'votes': 500}, {'code': 151, 'winner': None, 'candidates': [1, 2]}])
        merged, _ = rebase(old, proposed, live)
        self.assertEqual(json.loads(merged)['records'],
                         [{'code': 131, 'votes': 500}, {'code': 151, 'winner': 'A', 'candidates': [1, 2]}])

    def test_overlapping_changes_refuse_instead_of_overwriting(self):
        with self.assertRaisesRegex(ValueError, 'Overlapping live correction'):
            rebase(body([{'code': 1, 'votes': 100}]), body([{'code': 1, 'votes': 101}]),
                   body([{'code': 1, 'votes': 102}]))

    def test_already_applied_result_keeps_exact_live_bytes(self):
        old = body([{'code': 1, 'winner': None}])
        live = body([{'code': 1, 'winner': 'A'}]) + b'\n'
        self.assertEqual(rebase(old, live, live)[0], live)

    def test_new_live_and_proposed_constituencies_are_preserved(self):
        merged, _ = rebase(body([{'code': 1}]), body([{'code': 1}, {'code': 2}]),
                           body([{'code': 1}, {'code': 3}]))
        self.assertEqual([row['code'] for row in json.loads(merged)['records']], [1, 3, 2])

    def test_duplicate_codes_and_source_changes_refuse(self):
        with self.assertRaisesRegex(ValueError, 'duplicate constituency'):
            rebase(body([{'code': 1}, {'code': 1}]), body([{'code': 1}]), body([{'code': 1}]))
        changed_source = json.loads(body([{'code': 1}]))
        changed_source['year'] = 1992
        with self.assertRaisesRegex(ValueError, 'source identity'):
            rebase(body([{'code': 1}]), body([{'code': 1}]), json.dumps(changed_source).encode())


if __name__ == '__main__':
    unittest.main()
