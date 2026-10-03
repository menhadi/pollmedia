import copy
import hashlib
import json
from pathlib import Path
import tempfile
import unittest
import zipfile

import build_national_source_education_package as package
from build_national_source_education_package import digest, encoded


class SourceEducationPackageTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.root=Path(__file__).resolve().parents[1]/'exports/historical-1961-20261001'
        if not (cls.root/package.MAPPING).is_file():
            cls.root=Path(__file__).resolve().parent
        if not (cls.root/package.MAPPING).is_file():
            raise unittest.SkipTest('Pinned source mapping absent')
        package.prepare_prior(cls.root)
        cls.payload,cls.proofs=package.load_reviewed(cls.root)
        cls.prior=json.loads((cls.root/package.BASE_PAYLOAD).read_bytes())

    def test_prior_seventeen_rows_and_checks_remain_exact(self):
        self.assertEqual(self.payload['rows'][:17],self.prior['rows'])
        self.assertEqual(self.payload['checks'][:len(self.prior['checks'])],self.prior['checks'])
        self.assertEqual(self.payload['statistics'],package.EXPECTED)

    def test_additions_keep_source_scope_and_no_new_administrative_level(self):
        rows=self.payload['rows'][17:]
        self.assertEqual(len(rows),25)
        self.assertEqual(sum(r['source_scope_kind']=='SOURCE_AREA' for r in rows),22)
        self.assertEqual(sum(r['source_scope_kind']=='INFORMAL_STUDY_ZONE' for r in rows),3)
        self.assertTrue(all(r['original_level'] is None and r['parent_original_name'] is None
                            and r['modern_LGD_identifiers'] is None for r in rows))
        self.assertEqual(sum(len(r['values']) for r in rows),275)
        self.assertTrue(all(type(v) is int for r in rows for v in r['values'].values()))

    def test_repeated_areas_have_separate_keys_and_dates(self):
        for name in ['ANDAMAN AND NICOBAR ISLANDS','GOA, DAMAN AND DIU']:
            rows=[r for r in self.payload['rows'] if r['original_name']==name]
            self.assertEqual(len(rows),2)
            self.assertNotEqual(rows[0]['record_key'],rows[1]['record_key'])
            self.assertEqual(rows[0]['values'],rows[1]['values'])
        goa=[r for r in self.payload['rows'] if r['original_name']=='GOA, DAMAN AND DIU']
        self.assertTrue(all(r['enumeration_date']=='1960-12-15' for r in goa))
        dadra=next(r for r in self.payload['rows'] if r['original_name']=='DADRA AND NAGAR HAVELI')
        self.assertEqual(dadra['enumeration_date'],'1962-03-01')

    def test_original_nulls_discrepancies_and_held_rows_stay_distinct(self):
        self.assertEqual(sum(v is None for r in self.payload['rows'] for v in r['values'].values()),8)
        self.assertFalse(any(r['original_name'] in ['CENTRAL ZONE','EASTERN ZONE*','ASSAM','KERALA'] for r in self.payload['rows']))
        flags=[c for c in self.payload['checks'] if c['status']=='source_discrepancy']
        self.assertEqual(len(flags),7)
        new=[c['difference'] for c in flags if c.get('source_placement_key') in ['32022:C-III-A:129:1','32022:C-III-A:129:2']]
        self.assertEqual(new,[200,1])

    def test_bihar_key_and_nefa_covered_population_are_preserved(self):
        bihar=next(r for r in self.payload['rows'] if r['original_name']=='BIHAR')
        self.assertEqual(bihar['source_record_identity'],'32022|1961|C-III Part A|32022:C-III-A:119:3|All Areas|All ages')
        self.assertEqual(bihar['record_key'],'8d09c4996b1f79aa353676194e905b5baf8d9c84b90e985fadb37583e04d8e08')
        nefa=next(r for r in self.payload['rows'] if r['original_name']=='NORTH-EAST FRONTIER AGENCY*')
        self.assertEqual(nefa['raw_right_heading'],'NORTH-EAST FRONTIER AGENCY')
        self.assertEqual(nefa['values']['TOT_P'],38705)
        self.assertIn('nefa-partial-exclusion',nefa['warning_ids'])

    def test_repaired_count_or_unresolved_null_rejected_by_mapping(self):
        mapping=json.loads((self.root/package.MAPPING).read_bytes())
        receipt=json.loads((self.root/package.RECEIPT).read_bytes())
        docs={n:json.loads((self.root/n).read_bytes()) for n in receipt['input_hashes']}
        for kind in ['repair','null','level','key']:
            with self.subTest(kind=kind):
                changed=copy.deepcopy(mapping);row=next(r for r in changed['mapped_rows'] if r['original_name']=='TRIPURA')
                if kind=='repair':row['values']['TOT_F']=550968
                if kind=='null':row['values']['TOT_F']=None
                if kind=='level':row['original_level']='STATE'
                if kind=='key':row['record_key']='0'*64
                with self.assertRaises(ValueError):package.map_additions(changed,self.prior,docs)

    def test_round_trip_and_rehashed_payload_tampering_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            original=Path(directory)/'valid.zip';result=package.build(self.root,original)
            self.assertEqual(package.verify(original,result['sha256']),self.payload)
            with zipfile.ZipFile(original) as archive:
                members={n:archive.read(n) for n in archive.namelist()}
            for kind in ['prior','new','period','warning','classification','duplicate','flag','hold']:
                with self.subTest(kind=kind):
                    payload=copy.deepcopy(self.payload)
                    row=next(r for r in payload['rows'] if r['original_name']=='NORTH-EAST FRONTIER AGENCY*')
                    if kind=='prior':payload['rows'][0]['values']['TOT_P']+=1
                    if kind=='new':row['values']['TOT_P']+=297853
                    if kind=='period':next(r for r in payload['rows'] if r['original_name']=='GOA, DAMAN AND DIU')['enumeration_date']='1961-03-01'
                    if kind=='warning':row['warning_ids']=[]
                    if kind=='classification':row['original_level']='STATE'
                    if kind=='duplicate':payload['rows'].remove(next(r for r in payload['rows'] if r.get('source_placement_key')=='32022:C-III-A:125:4'))
                    if kind=='flag':payload['checks']=[c for c in payload['checks'] if c.get('source_placement_key')!='32022:C-III-A:129:2']
                    if kind=='hold':row['values']['ILLITERATE_F']=None
                    changed=dict(members);changed['education-population.json']=encoded(payload)
                    manifest=json.loads(changed['manifest.json']);manifest['files']['education-population.json']=hashlib.sha256(changed['education-population.json']).hexdigest()
                    changed['manifest.json']=encoded(manifest)
                    path=Path(directory)/f'{kind}.zip'
                    with zipfile.ZipFile(path,'w') as archive:
                        for name,raw in changed.items():archive.writestr(name,raw)
                    with self.assertRaisesRegex(ValueError,'Mapped payload'):
                        package.verify(path,digest(path))

    def test_unsafe_inventory_and_existing_output_rejected(self):
        with tempfile.TemporaryDirectory() as directory:
            path=Path(directory)/'valid.zip';package.build(self.root,path)
            with self.assertRaisesRegex(ValueError,'Output exists'):package.build(self.root,path)
            with zipfile.ZipFile(path,'a') as archive:archive.writestr('../extra.txt','unreviewed')
            with self.assertRaisesRegex(ValueError,'archive inventory'):package.verify(path,digest(path))


if __name__=='__main__':unittest.main()
