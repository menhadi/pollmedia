import unittest
from extract_assembly_2000s import parse

class DotTableTests(unittest.TestCase):
    def test_both_serial_positions_preserve_components_and_source_order(self):
        for row in ['. ONE\nM\n58\n2\nAAA\n40\nGEN\n60\n1\n','. ONE\nM\n58\n2\n1\nAAA\n40\nGEN\n60\n']:
            record=parse(row+'TOTAL:\n58\n2\n60')
            self.assertEqual(record['candidates'][0]['source_row'],1)
            self.assertEqual(record['candidates'][0]['general_votes'],58)
            self.assertEqual(record['valid_candidate_votes'],60)
            self.assertNotIn('winner',record)

    def test_uncontested_source_identity_is_retained_without_invented_votes(self):
        r=parse('. ONE\nM\n1\nUncontested\nAAA\n40\nGEN\nTOTAL:')
        self.assertEqual(r['candidates'][0]['candidate_name'],'ONE')
        self.assertIsNone(r['candidates'][0]['votes'])
        self.assertNotIn('winner',r)

    def test_unparsed_rows_and_component_discrepancies_stay_flagged(self):
        record=parse('. ONE\nM\n57\n2\nAAA\n40\nGEN\n60\n1\n. UNREADABLE\nTOTAL:\n58\n2\n60')
        self.assertIn('Some candidate',record['error'])
        self.assertIn('components differ',record['error'])
        self.assertEqual(len(record['candidates']),1)

if __name__=='__main__':unittest.main()
