import copy
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

import build_national_original_education_package as original
from build_national_education_expansion_package import MAPPING, build, digest, encoded, load_reviewed, map_state_rows, verify


class AdditiveStateEvidenceTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root=Path(__file__).resolve().parents[1]/'exports/historical-1961-20261001'
        if not (cls.root/MAPPING).is_file():
            raise unittest.SkipTest('Pinned original review mapping absent')
        cls.mapping=json.loads((cls.root/MAPPING).read_text(encoding='utf-8'))

    def test_unresolved_state_null_and_repaired_madras_value_are_rejected(self):
        for change in ['NULL','repair','authority']:
            with self.subTest(change=change):
                mapping=copy.deepcopy(self.mapping)
                if change=='NULL': mapping['mapped_rows'][0]['values']['ILLITERATE_F']=None
                if change=='repair': mapping['mapped_rows'][-1]['values']['ILLITERATE_F']-=50000
                if change=='authority': mapping['mapped_rows'][-1]['source_discrepancies'][0]['official_erratum_applied']=True
                with self.assertRaises(ValueError): map_state_rows(mapping)

    def test_wrong_placement_page_column_or_modern_code_is_rejected(self):
        for change in ['placement','page','column','code']:
            with self.subTest(change=change):
                mapping=copy.deepcopy(self.mapping);row=mapping['mapped_rows'][0]
                if change=='placement': row['source_placement_key']='32022:C-III-A:119:2'
                if change=='page': row['value_evidence']['TOT_P']['physical_page']=120
                if change=='column': row['value_evidence']['TOT_P']['source_column']=3
                if change=='code': row['modern_LGD_identifiers']={'state':'28'}
                with self.assertRaises(ValueError): map_state_rows(mapping)

    def test_source_flag_and_original_state_denominator_are_retained(self):
        rows,checks=map_state_rows(self.mapping)
        self.assertEqual(len(rows),5)
        self.assertEqual(rows[-1]['values']['TOT_F'],16775975)
        self.assertEqual(rows[-1]['values']['ILLITERATE_F'],13727682)
        self.assertTrue(any('difference 50000' in flag for flag in rows[-1]['flags']))
        self.assertEqual(sum(c['status']=='passed' for c in checks),14)
        self.assertEqual(sum(c['status']=='source_discrepancy' for c in checks),1)
        self.assertTrue(all('INDIA* NEFA exclusion is not applied' in row['definition_context'] for row in rows))
        self.assertFalse(any(row['original_name']=='KERALA' for row in rows))


class AdditiveNationalProductionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root=Path(__file__).resolve().parents[1]/'exports/historical-1961-20261001'
        if not all((cls.root/name).is_file() for name in [MAPPING,original.ORIGINAL,*original.PINS]):
            raise unittest.SkipTest('Preserved original inputs absent')

    def test_published_twelve_rows_remain_exact_and_only_five_states_are_added(self):
        old,_=original.load_reviewed(self.root);new,_=load_reviewed(self.root)
        self.assertEqual(new['rows'][:12],old['rows'])
        self.assertEqual(new['checks'][:len(old['checks'])],old['checks'])
        self.assertEqual(new['statistics']['value_cells'],187)
        self.assertEqual(new['statistics']['reported_integer_cells'],179)
        self.assertEqual(new['statistics']['original_dot_null_cells'],8)
        self.assertEqual(new['statistics']['arithmetic_discrepancies'],5)
        self.assertEqual(new['statistics']['arithmetic_passes'],49)
        self.assertEqual(len({row['record_key'] for row in new['rows']}),17)

    def test_package_round_trip_and_rehashed_numeric_tamper_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            package=Path(directory)/'valid.zip';result=build(self.root,package)
            self.assertEqual(len(verify(package,result['sha256'])['rows']),17)
            with zipfile.ZipFile(package) as source: members={name:source.read(name) for name in source.namelist()}
            payload=json.loads(members['education-population.json']);payload['rows'][0]['values']['TOT_P']+=1
            members['education-population.json']=encoded(payload)
            claimed=json.loads(members['manifest.json']);claimed['files']['education-population.json']=hashlib.sha256(members['education-population.json']).hexdigest()
            members['manifest.json']=encoded(claimed);changed=Path(directory)/'changed.zip'
            with zipfile.ZipFile(changed,'w') as archive:
                for name,raw in members.items(): archive.writestr(name,raw)
            with self.assertRaisesRegex(ValueError,'Mapped payload'): verify(changed,digest(changed))

    def test_extra_archive_member_and_output_overwrite_are_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            package=Path(directory)/'valid.zip';build(self.root,package)
            with self.assertRaisesRegex(ValueError,'Output exists'): build(self.root,package)
            with zipfile.ZipFile(package,'a') as archive: archive.writestr('../extra.txt','unreviewed')
            with self.assertRaisesRegex(ValueError,'archive inventory'): verify(package,digest(package))


if __name__=='__main__': unittest.main()
