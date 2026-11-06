import sqlite3
import unittest
from build_constituency_map_metrics import memberships


class ConstituencyMembershipTest(unittest.TestCase):
    def test_full_parents_expand_once_partial_parents_and_wards_do_not(self):
        db=sqlite3.connect(':memory:')
        db.executescript('CREATE TABLE ancestry(lgd,state,district,subdistrict); CREATE TABLE membership(state,pc,ac,entity_type,entity_code,coverage);')
        db.executemany('INSERT INTO ancestry VALUES (?,?,?,?)',[('1','9','151','800'),('2','9','151','800'),('3','9','152','801'),('4','8','151','800')])
        db.executemany('INSERT INTO membership VALUES (?,?,?,?,?,?)',[
            ('9','10','20','District','151','Fully Covered'),('9','10','20','Village','1','Fully Covered'),
            ('9','10','20','District','152','Partly Covered'),('9','10','20','Ward','3','Fully Covered'),
            ('9','10','20','Village','2','Partly Covered')])
        result,held=memberships(db)
        self.assertEqual({'1':False,'2':True},result[('9','ac','20')])
        self.assertEqual({'1':False,'2':True},result[('9','pc','10')])
        self.assertEqual(1,held['partial_parent_not_expanded'])
        self.assertEqual(1,held['urban_or_ward_membership_without_village_crosswalk'])

    def test_conflicting_ac_membership_is_withheld_but_shared_pc_is_deduplicated(self):
        db=sqlite3.connect(':memory:')
        db.executescript('CREATE TABLE ancestry(lgd,state,district,subdistrict); CREATE TABLE membership(state,pc,ac,entity_type,entity_code,coverage);')
        db.execute('INSERT INTO ancestry VALUES ("1","9","151","800")')
        db.executemany('INSERT INTO membership VALUES (?,?,?,?,?,?)',[
            ('9','10','20','Village','1','Fully Covered'),('9','10','21','Village','1','Fully Covered')])
        result,_=memberships(db)
        self.assertTrue(result[('9','ac','20')]['1'])
        self.assertTrue(result[('9','ac','21')]['1'])
        self.assertFalse(result[('9','pc','10')]['1'])


if __name__=='__main__':unittest.main()
