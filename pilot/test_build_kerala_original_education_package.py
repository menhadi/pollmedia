import copy
import hashlib
import json
import os
from pathlib import Path
import tempfile
import unittest
import zipfile

from build_kerala_original_education_package import CANDIDATE, INVENTORY, digest, encoded, map_rows, verify


class KeralaOriginalEducationMappingTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        root=Path(os.environ.get('POLLMEDIA_KERALA_TEST_ROOT',str(Path(__file__).resolve().parents[1]/'exports/historical-1961-20261001')))
        cls.root=root
        cls.original=json.loads((root/CANDIDATE).read_text())
        cls.inventory=json.loads((root/INVENTORY).read_text())

    def test_actual_source_counts_dots_and_legal_age_residence_identity(self):
        rows,stats=map_rows(self.original,self.inventory)
        self.assertEqual(len(rows),359)
        self.assertEqual(len({r['record_key'] for r in rows}),359)
        self.assertEqual(stats['original_dot_null_cells'],1580)
        self.assertEqual(stats['reported_integer_cells'],4769)
        self.assertTrue(all(r['modern_LGD_identifiers'] is None for r in rows))
        alleppey=next(r for r in rows if r['original_name']=='ALLEPPEY DISTRICT' and r['residence']=='Urban' and r['original_age_group']=='5-9')
        self.assertEqual(alleppey['values']['LIT_NOLEVEL_M'],8878)
        self.assertIsNone(alleppey['values']['MATRIC_HIGHER_SECONDARY_M'])
        self.assertEqual(alleppey['value_evidence']['MATRIC_HIGHER_SECONDARY_M']['missing_cell_notation'],'..')

    def test_official_errata_keep_raw_cells_and_exact_authority(self):
        rows,_=map_rows(self.original,self.inventory)
        state=next(r for r in rows if r['original_name']=='KERALA STATE' and r['residence']=='Urban' and r['original_age_group']=='All ages')
        self.assertEqual(state['values']['TOT_M'],1282759)
        self.assertEqual(state['value_evidence']['TOT_M']['original_printed_value'],1282579)
        self.assertEqual(state['value_evidence']['TOT_M']['official_correction']['authority_printed_target_page'],32)
        quilon=next(r for r in rows if r['original_name']=='QUILON DISTRICT' and r['residence']=='Rural' and r['original_age_group']=='Age not stated')
        self.assertEqual(quilon['values']['MATRIC_AND_ABOVE_F'],8)
        self.assertEqual(quilon['value_evidence']['MATRIC_AND_ABOVE_F']['original_printed_value'],3)

    def test_distinct_matriculation_classifications_and_printed_discrepancies_survive(self):
        rows,_=map_rows(self.original,self.inventory)
        self.assertTrue(all('MATRIC_HIGHER_SECONDARY_M' in r['values'] and 'MATRIC_AND_ABOVE_M' not in r['values'] for r in rows if r['residence']=='Urban'))
        self.assertTrue(all('MATRIC_AND_ABOVE_M' in r['values'] and 'MATRIC_HIGHER_SECONDARY_M' not in r['values'] for r in rows if r['residence']!='Urban'))
        state=next(r for r in rows if r['original_name']=='KERALA STATE' and r['residence']=='Urban' and r['original_age_group']=='All ages')
        self.assertEqual(state['values']['PRIMARY_F'],133228)
        self.assertTrue(any('183228' in note for note in state['flags']))

    def test_damaged_row_duplicate_and_identity_drift_are_rejected(self):
        for change in ['damaged','duplicate','age','name','page']:
            with self.subTest(change=change):
                d=copy.deepcopy(self.original)
                if change=='damaged':
                    r=copy.deepcopy(next(r for r in d['rows'] if r['source_placement_key']=='28296:C-III-A:43:1:25-29'))
                    r.update(source_placement_key='28296:C-III-A:43:1:30-34',age='30-34');d['rows'].append(r)
                elif change=='duplicate':d['rows'].append(copy.deepcopy(d['rows'][0]))
                elif change=='age':d['rows'][0]['age']='7+'
                elif change=='name':d['rows'][0]['geography']='Modern Kerala'
                else:d['rows'][0]['physical_pages']=[50,51]
                with self.assertRaises(ValueError):map_rows(d,self.inventory)

    def test_numeric_repair_boolean_and_unwitnessed_null_are_rejected(self):
        for change in ['repair','boolean','null','errata']:
            with self.subTest(change=change):
                d=copy.deepcopy(self.original);r=d['rows'][0]
                if change=='repair':r['effective_review_values']['PRIMARY_F']=183228
                elif change=='boolean':r['original_printed']['PRIMARY_F']=False;r['effective_review_values']['PRIMARY_F']=False
                elif change=='null':r['original_printed']['PRIMARY_F']=None;r['effective_review_values']['PRIMARY_F']=None
                else:r['official_corrections']['TOT_M']['authority_printed_target_page']=48
                with self.assertRaises(ValueError):map_rows(d,self.inventory)

    def test_rehashed_payload_repair_is_rejected_against_pinned_evidence(self):
        original=self.root/'kerala-original-education-1961-v1.zip'
        if not original.exists():self.skipTest('Guarded server archive not available in this checkout')
        self.assertEqual(len(verify(original,digest(original))['rows']),359)
        with zipfile.ZipFile(original) as source:
            members={n:source.read(n) for n in source.namelist()}
        payload=json.loads(members['education-population.json'])
        state=next(r for r in payload['rows'] if r['original_name']=='KERALA STATE' and r['residence']=='Urban' and r['original_age_group']=='All ages')
        state['values']['PRIMARY_F']=183228;state['flags']=[]
        members['education-population.json']=encoded(payload)
        manifest=json.loads(members['manifest.json']);manifest['files']['education-population.json']=hashlib.sha256(members['education-population.json']).hexdigest()
        members['manifest.json']=encoded(manifest)
        with tempfile.TemporaryDirectory(dir=self.root) as directory:
            changed=Path(directory)/'changed.zip'
            with zipfile.ZipFile(changed,'w') as archive:
                for name,raw in members.items():archive.writestr(name,raw)
            with self.assertRaisesRegex(ValueError,'Mapped payload'):verify(changed,digest(changed))

    def test_rehashed_extra_archive_member_is_rejected(self):
        original=self.root/'kerala-original-education-1961-v1.zip'
        if not original.exists():self.skipTest('Guarded server archive not available in this checkout')
        with tempfile.TemporaryDirectory(dir=self.root) as directory:
            changed=Path(directory)/'extra.zip'
            with zipfile.ZipFile(original) as source,zipfile.ZipFile(changed,'w') as archive:
                for name in source.namelist():archive.writestr(name,source.read(name))
                archive.writestr('evidence/unreviewed.json',b'{}')
            with self.assertRaisesRegex(ValueError,'Archive inventory'):verify(changed,digest(changed))


if __name__=='__main__':unittest.main()
