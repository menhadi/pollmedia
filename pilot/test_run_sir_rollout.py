import hashlib
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch
from run_sir_rollout import official, download


class RolloutTest(unittest.TestCase):
    def test_only_official_https_hosts_are_downloaded(self):
        self.assertTrue(official('https://www.eci.gov.in/roll.pdf'))
        self.assertTrue(official('https://pilibhit.nic.in/roll.pdf'))
        for url in ['http://www.eci.gov.in/x', 'https://eci.gov.in.evil.test/x', 'https://example.com/x']:
            self.assertFalse(official(url))

    def test_checksum_failure_never_replaces_original_file(self):
        class Response:
            is_redirect = False
            def raise_for_status(self): pass
            def iter_content(self, size): return iter([b'%PDF-wrong source'])
            def close(self): pass
        with tempfile.TemporaryDirectory() as folder:
            target = Path(folder)/'original.pdf'
            target.write_bytes(b'%PDF-original')
            with patch('run_sir_rollout.requests.get', return_value=Response()):
                with self.assertRaises(ValueError):
                    download('https://www.eci.gov.in/roll.pdf', target, hashlib.sha256(b'%PDF-correct').hexdigest())
            self.assertEqual(target.read_bytes(), b'%PDF-original')
            self.assertFalse(target.with_suffix('.partial').exists())

    def test_success_preserves_exact_pdf_bytes(self):
        source = b'%PDF-matching original'
        class Response:
            is_redirect = False
            def raise_for_status(self): pass
            def iter_content(self, size): return iter([source])
            def close(self): pass
        with tempfile.TemporaryDirectory() as folder:
            target = Path(folder)/'original.pdf'
            with patch('run_sir_rollout.requests.get', return_value=Response()):
                download('https://www.eci.gov.in/roll.pdf', target, hashlib.sha256(source).hexdigest())
            self.assertEqual(target.read_bytes(), source)


if __name__ == '__main__':
    unittest.main()
