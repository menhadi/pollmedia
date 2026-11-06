import unittest
import tempfile
import sqlite3
import json
from pathlib import Path
from build_india_overview_metrics import summaries, build


class OverviewMetricsTest(unittest.TestCase):
    def test_literacy_is_weighted_by_eligible_population(self):
        rows = [[1, 2, '09', '151', '00800', '100001', 110, 10, 10, 100],
                [2, 2, '09', '151', '00800', '100002', 910, 90, 10, 90]]
        value = summaries(rows)
        self.assertEqual(1020, value['population'])
        self.assertEqual(100, value['households'])
        self.assertEqual(19, value['literacy'])

    def test_zero_missing_and_invalid_literacy_remain_distinct(self):
        value = summaries([[1, 2, '09', '151', '00800', '100001', 0, 0, 0, 0],
                           [2, 2, '09', '151', '00800', '100002', None, None, None, None],
                           [3, 2, '09', '151', '00800', '100003', 100, 5, 20, 90]])
        self.assertEqual(100, value['population'])
        self.assertIsNone(value['literacy'])
        self.assertEqual(2, value['counts']['households'])
        self.assertIsNone(summaries([])['population'])

    def test_duplicate_editions_are_not_added_twice_and_split_crosswalks_are_withheld(self):
        with tempfile.TemporaryDirectory() as temporary:
            root=Path(temporary);directory=root/'storage/app/electoral-villages';directory.mkdir(parents=True)
            db=sqlite3.connect(directory/'links.sqlite');db.executescript('CREATE TABLE directory(lgd,state,census2011); CREATE TABLE ancestry(lgd,state,district,subdistrict);')
            db.executemany('INSERT INTO directory VALUES (?,?,?)',[('1','9','100001'),('2','9','100002'),('3','9','100002')])
            db.executemany('INSERT INTO ancestry VALUES (?,?,?,?)',[('1','9','151','800'),('2','9','151','800'),('3','9','152','801')]);db.commit();db.close()
            rows=[[10,20,'09','151','00800','100001',100,10,10,45],[11,19,'09','151','00800','100001',200,20,20,90],[12,20,'09','151','00800','100002',500,50,50,300]]
            inventory=root/'values.jsonl';inventory.write_text(''.join(json.dumps(row)+'\n' for row in rows));sources=root/'sources.json';sources.write_text('[]');output=root/'result.json'
            original=(directory/'links.sqlite').read_bytes();build(root,inventory,sources,output);result=json.loads(output.read_text())
            self.assertEqual(original,(directory/'links.sqlite').read_bytes())
            self.assertEqual(1,result['linked_villages'])
            self.assertEqual(100,result['groups']['9:151:']['population'])
            self.assertEqual(50,result['groups']['9:151:800']['literacy'])
            self.assertNotIn('9:152:',result['groups'])
            self.assertEqual(1,result['held']['split_lgd_census_crosswalk'])


if __name__ == '__main__':
    unittest.main()
