import unittest
from unittest.mock import patch
from extract_assembly_components import extract,parse_body
from test_assembly_legacy import Page,Document

ROW='ONE\nM\n51\nGEN\nAAA\n2\n60\n58\n60.00\n1\nTWO\nF\n42\nSC\nBBB\n0\n40\n40\n40.00\n2\n'
TOTAL='2\n100\nTOTAL:\n98'

class ComponentsTest(unittest.TestCase):
    def test_components_keep_postal_and_general_order(self):
        record=parse_body(ROW+TOTAL)
        self.assertEqual(record['candidates'][0]['postal_votes'],2)
        self.assertEqual(record['candidates'][0]['general_votes'],58)
        self.assertEqual(record['valid_candidate_votes'],100)
        self.assertNotIn('votes_polled',record)
        self.assertNotIn('winner',record)
        self.assertNotIn('do not match',record['error'])

    def test_partial_rows_and_component_mismatch_are_flagged(self):
        record=parse_body(ROW.replace('58\n','57\n')+'UNREADABLE\n'+TOTAL)
        self.assertIn('Some candidate',record['error'])
        self.assertIn('components do not match',record['error'])
        self.assertEqual(len(record['candidates']),2)

    def test_identity_variants_and_multiline_names(self):
        for identity in ['TOTAL ELECTORS :\n1.\n150\nSample\nConstituency\n','Sample\n1.\nConstituency\nTOTAL ELECTORS :\n150\n']:
            page=Page('DETAILED RESULTS\nVALID VOTES POLLED\n'+identity+ROW.replace('ONE\n','ONE NAME\nCONTINUED\n')+TOTAL+'\nPage 1 of 1')
            with patch('extract_assembly_components.fitz.open',return_value=Document([page])):
                record=extract('report.pdf','Example')[0]
            self.assertEqual(record['name'],'Sample')
            self.assertEqual(record['electors'],150)
            self.assertEqual(record['candidates'][0]['candidate_name'],'ONE NAME CONTINUED')

    def test_nota_not_reinterpreted_as_candidate_layout(self):
        with patch('extract_assembly_components.fitz.open',return_value=Document([Page('DETAILED RESULTS\nVALID VOTES POLLED\nNOTA')])):
            with self.assertRaisesRegex(ValueError,'NOTA'): extract('report.pdf','Example')

if __name__=='__main__':unittest.main()
