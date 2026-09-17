import json
from pathlib import Path
import unittest
from extract_election_import import extract

ROOT = Path(__file__).resolve().parent.parent
RAW = ROOT / 'pilot/raw/elections'

class ElectionExtractionTest(unittest.TestCase):
    def test_official_archives_match_all_existing_pc_and_ac_editions(self):
        pc = json.loads((ROOT / 'application/database/fixtures/pilibhit-elections.json').read_text(encoding='utf-8'))
        ac = json.loads((ROOT / 'application/database/fixtures/pilibhit-assembly.json').read_text(encoding='utf-8'))
        for fixture in pc + ac:
            kind = 'ac' if 'slug' in fixture else 'pc'
            scope = dict(type=kind, code=fixture.get('code',26), year=fixture['year'], name=fixture.get('slug','Pilibhit'))
            detail = RAW / ('2022-up-detailed.xlsx' if kind=='ac' else f"{fixture['year']}-detailed.pdf")
            summary = RAW / ('2022-up-summary.xlsx' if kind=='ac' else '2019-summary.pdf') if kind=='ac' or fixture['year']==2019 else None
            with self.subTest(scope=scope):
                result = extract(detail,summary,scope)
                for key in ['electors','votes_polled','valid_candidate_votes','candidates','sha256']:
                    self.assertEqual(fixture[key], result[key], key)

    def test_wrong_edition_file_pair_is_rejected(self):
        with self.assertRaises((ValueError,IndexError)):
            extract(RAW/'2019-detailed.pdf',None,dict(type='pc',code=26,year=2024,name='Pilibhit'))

if __name__ == '__main__':
    unittest.main()
