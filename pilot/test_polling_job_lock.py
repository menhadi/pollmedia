import tempfile
import threading
import unittest
from pathlib import Path
from polling_job_lock import extraction_lock


class ExtractionLockTest(unittest.TestCase):
    def test_second_batch_waits_until_first_releases_the_slot(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory)/'extraction.lock'
            entered, acquired = threading.Event(), threading.Event()

            def contender():
                entered.set()
                with extraction_lock(path):
                    acquired.set()

            with extraction_lock(path):
                thread = threading.Thread(target=contender)
                thread.start()
                self.assertTrue(entered.wait(5))
                self.assertFalse(acquired.wait(0.1))
            self.assertTrue(acquired.wait(5))
            thread.join(timeout=5)
            self.assertFalse(thread.is_alive())


if __name__ == '__main__':
    unittest.main()
