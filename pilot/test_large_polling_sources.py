import hashlib
from pathlib import Path
from tempfile import TemporaryDirectory
from types import SimpleNamespace
import unittest
from unittest.mock import patch
from collect_large_polling_sources import download


class LargeDownloadTest(unittest.TestCase):
    def test_preserves_streamed_pdf_and_rejects_corrupted_existing_copy(self):
        payload = b'%PDF-preserved report\n' * 1000
        url = 'https://ceo.example.gov.in/form20.pdf'
        with TemporaryDirectory() as directory:
            folder = Path(directory)
            def transfer(command, **kwargs):
                Path(command[command.index('--output')+1]).write_bytes(payload)
                return SimpleNamespace(returncode=0, stdout=url.encode())
            with patch('collect_large_polling_sources.subprocess.run', side_effect=transfer):
                record = download(url, folder)
                self.assertEqual(record['sha256'], hashlib.sha256(payload).hexdigest())
                self.assertEqual((folder/record['file']).read_bytes(), payload)
                self.assertFalse((folder/'large-document.part').exists())
                (folder/record['file']).write_bytes(b'changed')
                with self.assertRaisesRegex(ValueError, 'checksum changed'):
                    download(url, folder)
                self.assertFalse((folder/'large-document.part').exists())


if __name__ == '__main__':
    unittest.main()
