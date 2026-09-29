import hashlib
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

import extract_jjm_html as adapter
from census_server_worker import ResourceWait
from civic_source_backlog import summarize


class JjmTests(unittest.TestCase):
    def test_snapshot_guards_and_duplicate_totals_preserve_evidence(self):
        source = ('State wise PWS and FHTC Coverage\r\n<table>'
                  '<tr><th colspan="2">FHTC</th></tr>'
                  '<tr><td>Total</td><td>001</td></tr>'
                  '<tr><td>A &amp; B</td><td>NA</td></tr>'
                  '<tr><td>Total</td><td>001</td></tr></table>')
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); package = root/'report.html'
            package.write_bytes(source.encode())
            digest = lambda p: hashlib.sha256(p.read_bytes()).hexdigest()
            expected = digest(package)
            job = {'source_url': adapter.SOURCE_URL}
            with patch.dict(adapter.PROFILES, {expected: adapter.PROFILES[adapter.ORIGINAL_SHA256]}):
                with self.assertRaises(ValueError):
                    adapter.extract(root, package, expected, {'source_url':'wrong'}, digest, lambda _:True)
                with self.assertRaises(ResourceWait):
                    adapter.extract(root, package, expected, job, digest, lambda _:False)
                adapter.extract(root, package, expected, job, digest, lambda _:True)
                cells = [json.loads(line) for line in (root/'source-evidence'/f'jjm-html-{expected}.cells.jsonl').read_text().splitlines()]
                self.assertEqual([c['text'] for c in cells], ['FHTC','Total','001','A & B','NA','Total','001'])
                for cell in cells:
                    self.assertEqual(source[cell['start_character']:cell['end_character']], cell['raw_html'])
                manifest = json.loads((root/'source-evidence'/f'jjm-html-{expected}.manifest.json').read_text())
                self.assertEqual(manifest['review_state'], 'PENDING ADMIN REVIEW')
                package.write_bytes(b'changed')
                with self.assertRaises(ValueError):
                    adapter.extract(root, package, expected, job, digest, lambda _:True)

    def test_j17_profile_cannot_accept_j1_url_or_heading(self):
        digest = lambda p: hashlib.sha256(p.read_bytes()).hexdigest()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); package = root/'report.html'
            for heading, succeeds in [('State wise PWS and FHTC Coverage', False),
                                       ('Analysis of tap water connections in Districts', True)]:
                package.write_text(heading+'<table><tr><td>0</td><td>NA</td></tr></table>')
                expected = digest(package)
                with patch.dict(adapter.PROFILES, {expected: adapter.PROFILES[adapter.J17_SHA256]}):
                    with self.assertRaises(ValueError):
                        adapter.extract(root, package, expected, {'source_url':adapter.SOURCE_URL}, digest, lambda _:True)
                    if not succeeds:
                        with self.assertRaises(ValueError):
                            adapter.extract(root, package, expected, {'source_url':adapter.J17_URL}, digest, lambda _:True)
                    else:
                        adapter.extract(root, package, expected, {'source_url':adapter.J17_URL}, digest, lambda _:True)
                        manifest = json.loads((root/'source-evidence'/f'jjm-html-{expected}.manifest.json').read_text())
                        self.assertEqual(manifest['report'], 'J17')
                        self.assertIn('percentages are not additive', manifest['limitations'][-1])

    def test_monitor_uses_jjm_manifest(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            for d in ['queue','packages','source-evidence']: (root/d).mkdir()
            job = dict(kind='jjm_html', package='jjm.html', sha256='a'*64, source_url=adapter.SOURCE_URL)
            (root/'queue/html-a.json').write_text(json.dumps(job))
            (root/'packages/jjm.html').write_text('original')
            self.assertEqual(summarize(root)['counts'], {'html_queued_pending_extraction':1})
            (root/'source-evidence'/('jjm-html-'+'a'*64+'.manifest.json')).write_text(json.dumps({'original_sha256':'a'*64}))
            self.assertEqual(summarize(root)['counts'], {'html_evidence_present_pending_review':1})

    def test_f26_keeps_approval_and_denominator_caveats(self):
        digest = lambda p: hashlib.sha256(p.read_bytes()).hexdigest()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); package = root/'report.html'
            package.write_text('Status of Pipe Water Supply in School<table><tr><th>Approved Entry</th></tr><tr><td>001</td></tr></table>')
            expected = digest(package)
            with patch.dict(adapter.PROFILES, {expected: adapter.PROFILES[adapter.F26_SHA256]}):
                with self.assertRaises(ValueError):
                    adapter.extract(root, package, expected, {'source_url':adapter.J17_URL}, digest, lambda _:True)
                adapter.extract(root, package, expected, {'source_url':adapter.F26_URL}, digest, lambda _:True)
                manifest = json.loads((root/'source-evidence'/f'jjm-html-{expected}.manifest.json').read_text())
                self.assertEqual(manifest['report'], 'F26')
                self.assertEqual(manifest['review_state'], 'PENDING ADMIN REVIEW')
                self.assertIn('different denominators', manifest['limitations'][-1])
                self.assertIn('not Pollmedia approval', manifest['limitations'][-1])

    def test_f27_keeps_institution_and_connection_counts_distinct(self):
        digest = lambda p: hashlib.sha256(p.read_bytes()).hexdigest()
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory); package = root/'report.html'
            package.write_text('F27 Public Institutions<table><tr><th>Total</th><th>Availability of Tap Connection</th></tr><tr><td>10</td><td>0</td></tr></table>')
            expected = digest(package)
            with patch.dict(adapter.PROFILES, {expected:adapter.PROFILES[adapter.F27_SHA256]}):
                with self.assertRaises(ValueError):
                    adapter.extract(root, package, expected, {'source_url':adapter.F26_URL}, digest, lambda _:True)
                adapter.extract(root, package, expected, {'source_url':adapter.F27_URL}, digest, lambda _:True)
                evidence = root/'source-evidence'
                cells = [json.loads(line) for line in (evidence/f'jjm-html-{expected}.cells.jsonl').read_text().splitlines()]
                self.assertEqual([c['text'] for c in cells][-2:], ['10','0'])
                manifest = json.loads((evidence/f'jjm-html-{expected}.manifest.json').read_text())
                self.assertEqual(manifest['report'], 'F27')
                self.assertIn('do not establish healthcare availability', manifest['limitations'][-1])


if __name__ == '__main__': unittest.main()
