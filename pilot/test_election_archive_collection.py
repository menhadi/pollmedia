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
            content=json.dumps({'totalResults':2,'results':[dict(title='33.Constituency Wise Detailed Result',pdf_zip_url=summary_url.replace('32-', '33-')),dict(title='32.Constituency Data Summary',pdf_zip_url=summary_url)]}).encode() if '/api/' in source else data
            destination.write_bytes(content)
            return content
        with tempfile.TemporaryDirectory() as tmp:
            root=Path(tmp)
            with patch('collect_election_archive.fetch',side_effect=download):result=collect('pc','2024',url,root)
            self.assertEqual(len(result['files']),2)
            self.assertEqual(result['status'],'collected')
            summary=result['files'][1]
            self.assertEqual(summary['sha256'],hashlib.sha256(data).hexdigest())
            self.assertEqual(summary['source_url'],summary_url)
            with patch('collect_election_archive.fetch',side_effect=ValueError('Temporary download failure')):retried=collect('pc','2024',url,root)
            self.assertEqual(retried['files'][1],summary)
            self.assertEqual((root/key(url)/summary['file']).read_bytes(),data)

    def test_collects_spreadsheets_and_rejects_html_without_losing_other_files(self):
        url='https://www.eci.gov.in/general-election-to-loksabha-2024-statistical-reports'
        base='https://www.eci.gov.in/eci-backend/public/all_files/'
        def download(source,destination):
            if '/api/' in source:
                content=json.dumps({'totalResults':2,'results':[dict(title='1.Schedule',pdf_zip_url=base+'schedule.pdf',xlsx_url=base+'schedule.xls'),dict(title='2.Highlights',pdf_zip_url=base+'bad.pdf')]}).encode()
            elif source.endswith('.xls'):
                content=bytes.fromhex('d0cf11e0a1b11ae1')+b'workbook'
            elif source.endswith('bad.pdf'):
                content=b'<html>Access check</html>'
            else:
                content=b'%PDF-1.7 report'
            destination.write_bytes(content)
            return content
        with tempfile.TemporaryDirectory() as tmp:
            with patch('collect_election_archive.fetch',side_effect=download):
                result=collect('pc','2024',url,Path(tmp))
            self.assertEqual(result['status'],'partial')
            self.assertEqual(result['expected_files'],3)
            self.assertEqual(len(result['files']),2)
            self.assertEqual(len(result['errors']),1)
            self.assertTrue(any(f['file'].endswith('.xls') for f in result['files']))

    def test_incomplete_catalogue_is_not_claimed_as_collected(self):
        with tempfile.TemporaryDirectory() as tmp:
            with patch('collect_election_archive.fetch',return_value=json.dumps({'totalResults':2,'results':[{'title':'one'}]}).encode()):
                result=collect('pc','2024','https://www.eci.gov.in/reports',Path(tmp))
            self.assertEqual(result['status'],'failed')

    def test_assembly_uses_its_own_category_and_keeps_state_provenance(self):
        url = 'https://www.eci.gov.in/statistical-report/ae/2024/6'
        def download(source, destination):
            if '/api/' in source:
                self.assertTrue(source.endswith('category_id=6'))
                content = json.dumps({'totalResults': 1, 'results': [{'title': '32. Report', 'pdf_zip_url': 'https://www.eci.gov.in/report.pdf'}]}).encode()
            else:
                content = b'%PDF-1.7 fixture'
            destination.write_bytes(content)
            return content
        with tempfile.TemporaryDirectory() as tmp:
            with patch('collect_election_archive.fetch', side_effect=download):
                result = collect('ac', '2024 Haryana', url, Path(tmp))
            self.assertEqual(result['status'], 'collected')
            self.assertEqual(result['files'][0]['source_page'], url)
            self.assertNotIn('-summary', result['files'][0]['file'])

    def test_discovery_preserves_special_editions_and_historical_state_names(self):
        from discover_assembly_archive import parse
        page = '<table><tr><td>Bombay</td><td><a href="https://old.eci.gov.in/files/file/1-report/">1951</a></td></tr><tr><td>West Bengal</td><td><a href="statistical-report/ae/2026/28">2026(Including AC-144)</a></td></tr></table>'
        result = parse(json.dumps({'cmsPagesData': {'page_content': page}}).encode())
        self.assertEqual(len(result['entries']), 2)
        self.assertEqual(result['entries'][0]['state'], 'Bombay')
        self.assertEqual(result['entries'][1]['label'], '2026(Including AC-144)')
        self.assertEqual(result['entries'][1]['year'], 2026)

    def test_missing_mode_rechecks_checksums_and_does_not_trust_status_alone(self):
        from collect_election_archive import intact_collection
        url = 'https://www.eci.gov.in/statistical-report/ae/2024/6'
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp); folder = root/key(url); folder.mkdir()
            (folder/'source.pdf').write_bytes(b'%PDF-source')
            manifest = {'url': url, 'status': 'collected', 'files': [{'file':'source.pdf','sha256':hashlib.sha256(b'%PDF-source').hexdigest()}]}
            (folder/'manifest.json').write_text(json.dumps(manifest))
            self.assertTrue(intact_collection(url, root))
            (folder/'source.pdf').write_bytes(b'changed')
            self.assertFalse(intact_collection(url, root))

    def test_static_assembly_sources_keep_distinct_rajasthan_variants(self):
        fixture = json.loads((Path(__file__).resolve().parents[1]/'application/database/fixtures/eci-assembly-static.json').read_text())
        editions = fixture['editions']
        including = editions['https://www.eci.gov.in/rajasthan-legislative-election-2023-including-statistical-report']
        excluding = editions['https://www.eci.gov.in/rajasthan-legislative-election-2023-statistical-report']
        self.assertEqual(len(including),14)
        self.assertTrue(all('/2023_Including/' in r['pdf_zip_url'] for r in including))
        self.assertTrue(all('/2023/' in r['pdf_zip_url'] for r in excluding))

    def test_category_excludes_global_recent_download_widgets(self):
        soup=BeautifulSoup('<div class="cDownloadsCategoryTable"><a title="View the file Result" href="https://old.eci.gov.in/files/file/1-report/">Result</a></div><div class="ipsWidget"><a title="View the file unrelated" href="https://old.eci.gov.in/files/file/2-other/">Other year</a></div>', 'html.parser')
        self.assertEqual(['https://old.eci.gov.in/files/file/1-report/'],report_pages(soup))

    def test_non_official_downloads_are_rejected_before_network_access(self):
        with self.assertRaises(ValueError):
            fetch('https://example.com/report.pdf',Path('unused.pdf'))

if __name__ == '__main__':
    unittest.main()
