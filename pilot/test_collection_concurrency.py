import tempfile
import unittest
from pathlib import Path
from collect_national_egramswaraj_details import capture_files,payment_budget

class CollectionConcurrencyTest(unittest.TestCase):
    def test_payment_and_activity_captures_alternate_without_duplicates(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            for name in ['capture-activities-01.zip','capture-activities-02.zip','capture-financial-01.zip','capture-financial-02.zip','capture-financial-03.zip']:(root/name).touch()
            chosen=capture_files(root)
            self.assertEqual(['capture-financial-01.zip','capture-activities-01.zip','capture-financial-02.zip','capture-activities-02.zip'],[p.name for p in chosen])
            self.assertEqual(5,len(list(root.glob('*.zip'))))
    def test_one_pool_can_use_all_publication_slots(self):
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            for n in range(6):(root/('capture-financial-'+str(n)+'.zip')).touch()
            self.assertEqual(4,len(capture_files(root)))
    def test_backlog_reduces_payment_budget_without_stopping_activities(self):
        self.assertEqual(12,payment_budget(0));self.assertEqual(12,payment_budget(7));self.assertEqual(8,payment_budget(8));self.assertEqual(8,payment_budget(100))
if __name__=='__main__':unittest.main()
