import copy
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

from build_national_original_education_package import AGES, FIELDS, PINS, build, digest, encoded, load_reviewed, map_rows, verify


def original_rows():
    rows = []
    for position, age in enumerate(AGES,1):
        values, evidence, missing = {}, {}, {}
        for col, field in enumerate(FIELDS,2):
            is_dot = (age=='0-4' and col>=7) or (age=='5-9' and col>=11)
            values[field] = None if is_dot else 0
            page = 115 if col<=6 else 116
            evidence[field] = dict(source_column=col,physical_page=page,printed_page=page-7,
                                   render_sha256='a'*64,original_row_position_within_geography=position)
            if is_dot:
                missing[field] = {'original_mark':'..'}
        rows.append(dict(original_geography='INDIA*',original_table='C-III Part A',original_residence='All Areas',
                         original_age_group=age,unit='persons',values=values,value_evidence=evidence,missing_cells=missing))
    return rows


class NationalOriginalEducationMappingTest(unittest.TestCase):
    def test_original_dots_and_reported_zero_remain_distinct(self):
        rows,checks = map_rows(original_rows())
        self.assertIsNone(rows[1]['values']['MATRIC_AND_ABOVE_F'])
        self.assertEqual(rows[3]['values']['MATRIC_AND_ABOVE_F'],0)
        self.assertEqual(rows[1]['value_evidence']['MATRIC_AND_ABOVE_F']['missing_cell_notation'],'..')
        self.assertEqual(sum(c['status']=='skipped_original_missing_cells' for c in checks),10)
        self.assertEqual(len({r['record_key'] for r in rows}),12)
        self.assertTrue(all(r['modern_LGD_identifiers'] is None for r in rows))

    def test_unresolved_review_null_is_rejected(self):
        source = original_rows()
        source[3]['values']['ILLITERATE_M'] = None
        source[3]['missing_cells']['ILLITERATE_M'] = {'original_mark':'..','meaning':'unresolved digit'}
        with self.assertRaisesRegex(ValueError,'Unreviewed NULL'):
            map_rows(source)

    def test_discrepancy_is_not_repaired_and_note_targets_correct_age(self):
        source = original_rows()
        source[5]['values']['TOT_M'] = 5000
        rows,checks = map_rows(source)
        row = rows[5]
        self.assertEqual(row['original_age_group'],'20-24')
        self.assertEqual(row['values']['TOT_M'],5000)
        self.assertTrue(any('Printed discrepancy:' in note and 'reported 5000' in note for note in row['flags']))
        self.assertTrue(any(c['age_group']=='20-24' and c['difference']==-5000 for c in checks if c['status']=='source_discrepancy'))

    def test_age_identity_or_locator_drift_is_rejected(self):
        for mutation in ['age','page','column','boolean']:
            with self.subTest(mutation=mutation):
                source=original_rows()
                if mutation=='age': source[3]['original_age_group']='7+'
                if mutation=='page': source[3]['value_evidence']['TOT_P']['physical_page']=119
                if mutation=='column': source[3]['value_evidence']['TOT_P']['source_column']=3
                if mutation=='boolean': source[3]['values']['TOT_P']=False
                with self.assertRaises(ValueError): map_rows(source)


class NationalOriginalEducationProductionTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root=Path(__file__).resolve().parents[1]/'exports/historical-1961-20261001'
        if not all((cls.root/name).is_file() for name in [*PINS,'22949_1961_SCT.pdf']):
            raise unittest.SkipTest('Preserved official originals absent; unit rejection checks still run')

    def test_pinned_original_counts_nulls_and_four_discrepancies(self):
        payload,_=load_reviewed(self.root)
        self.assertEqual(payload['statistics']['reported_integer_cells'],124)
        self.assertEqual(payload['statistics']['original_dot_null_cells'],8)
        self.assertEqual(payload['statistics']['arithmetic_passes'],35)
        flagged=[c for c in payload['checks'] if c['status']=='source_discrepancy']
        self.assertEqual(sorted(c['difference'] for c in flagged),[-5000,-1900,-1900,10000])
        row=next(r for r in payload['rows'] if r['original_age_group']=='20-24')
        self.assertEqual(row['values']['TOT_F'],19133698)
        self.assertEqual(payload['rows'][0]['values']['TOT_P'],438936918)

    def test_rehashed_payload_tamper_is_rejected_even_with_valid_member_hash(self):
        with tempfile.TemporaryDirectory() as directory:
            original=Path(directory)/'reviewed.zip'
            build(self.root,original)
            self.assertEqual(len(verify(original,digest(original))['rows']),12)
            changed=Path(directory)/'changed.zip'
            with zipfile.ZipFile(original) as source:
                members={n:source.read(n) for n in source.namelist()}
            payload=json.loads(members['education-population.json'])
            payload['rows'][0]['values']['TOT_P']+=297853
            payload['rows'][0]['flags']=[]
            members['education-population.json']=encoded(payload)
            manifest=json.loads(members['manifest.json'])
            manifest['files']['education-population.json']=hashlib.sha256(members['education-population.json']).hexdigest()
            members['manifest.json']=encoded(manifest)
            with zipfile.ZipFile(changed,'w') as target:
                for name,raw in members.items(): target.writestr(name,raw)
            with self.assertRaisesRegex(ValueError,'Mapped payload'):
                verify(changed,digest(changed))


if __name__=='__main__':
    unittest.main()
