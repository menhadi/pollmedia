import unittest
from bs4 import BeautifulSoup
from collect_election_archive import report_pages, fetch, collect, key
from pathlib import Path
from unittest.mock import patch
import tempfile
import json
import hashlib

class ArchiveCollectionTest(unittest.TestCase):
    def test_modern_summary_is_archived_and_retained_after_download_failure(self):
        url='https://www.eci.gov.in/general-election-to-loksabha-2024-statistical-reports'
        summary_url='https://www.eci.gov.in/eci-backend/public/all_files/GE-2024-statistical-report/32-Constituency_data_summery_report.pdf'
        data=b'%PDF-1.7 summary fixture'
        def download(source,destination):
            content=json.dumps({'results':[dict(title='32.Constituency Data Summary',pdf_zip_url=summary_url)]}).encode() if '/api/' in source else data
            destination.write_bytes(content)
            return content
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            with patch('collect_election_archive.fetch',side_effect=download):result=collect('pc','2024',url,root)
            self.assertEqual(len(result['files']),2)
            summary=result['files'][1]
            self.assertEqual(summary['sha256'],hashlib.sha256(data).hexdigest())
            self.assertEqual(summary['source_url'],summary_url)
            with patch('collect_election_archive.fetch',side_effect=ValueError('Temporary download failure')):retried=collect('pc','2024',url,root)
            self.assertEqual(retried['files'][1],summary)
            self.assertEqual((root/key(url)/summary['file']).read_bytes(),data)

    def test_category_excludes_global_recent_download_widgets(self):
        soup=BeautifulSoup('<div class="cDownloadsCategoryTable"><a title="View the file Result" href="https://old.eci.gov.in/files/file/1-report/">Result</a></div><div class="ipsWidget"><a title="View the file unrelated" href="https://old.eci.gov.in/files/file/2-other/">Other year</a></div>', 'html.parser')
        self.assertEqual(['https://old.eci.gov.in/files/file/1-report/'],report_pages(soup))

    def test_non_official_downloads_are_rejected_before_network_access(self):
        with self.assertRaises(ValueError):
            fetch('https://example.com/report.pdf',Path('unused.pdf'))

if __name__ == '__main__':
    unittest.main()
