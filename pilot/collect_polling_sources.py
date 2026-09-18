"""Discover official Form 20 sources across the ECI-listed CEO websites."""
import argparse
from concurrent.futures import ThreadPoolExecutor
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
import shutil
import subprocess
from urllib.parse import urljoin, urlparse, urldefrag, quote, parse_qs, urlencode
from bs4 import BeautifulSoup

DIRECTORY = 'https://www.eci.gov.in/eci-backend/public/api/get-election-data?page_seo_name=links-to-ceos'
FORM = re.compile(r'form[\s_().-]*20\b|form20|final[\s_-]*result[\s_-]*sheet|polling[\s_-]*station[\s_-]*wise.*result|booth[\s_-]*wise.*result', re.I)
PAGE = re.compile(r'form.?20|result|statisti|archive|past.?election|election.?histor|lok.?sabha|vidhan.?sabha|bye.?election|assembly.?election|parliamentary.?election|general.?election', re.I)


def official(url):
    parsed = urlparse(url)
    host = (parsed.hostname or '').lower()
    # These files are linked by https://ceoarunachal.nic.in/form20ae2024.
    arunachal_archive = host == '164.100.149.171' and parsed.port is None and bool(re.fullmatch(r'/form20ae2024/[0-9]+\.pdf', parsed.path))
    return parsed.scheme in ['https', 'http'] and (host.endswith('.gov.in') or host.endswith('.nic.in') or arunachal_archive)


def fetch(url, target, form=None, cookies=None, referer=None, ajax=False):
    if not official(url):
        raise ValueError('Non-official source URL')
    if shutil.disk_usage(target.parent).free < 2_000_000_000:
        raise ValueError('Local archive disk has less than 2 GB free; source download deferred')
    timeout = '120' if target.name.startswith('document') and target.suffix == '.part' else '25'
    command = ['curl.exe', '--silent', '--show-error', '--fail', '--location', '--max-redirs', '5', '--connect-timeout', '15', '--max-time', timeout,
               '--max-filesize', '100000000', '--write-out', '%{url_effective}', url, '-o', str(target)]
    if shutil.which('curl.exe') is None:
        command[0] = 'curl'
    if ajax:
        command.extend(['--header', 'X-Requested-With: XMLHttpRequest'])
    if cookies is not None:
        command.extend(['--cookie', str(cookies), '--cookie-jar', str(cookies)])
    if referer is not None:
        if not official(referer):
            raise ValueError('Non-official referring page')
        command.extend(['--referer', referer])
    for key, value in (form or {}).items():
        command.extend(['--data-urlencode', key+'='+str(value)])
    result = subprocess.run(command, capture_output=True)
    if result.returncode:
        target.unlink(missing_ok=True)
        raise ValueError('Official download failed; curl '+str(result.returncode))
    final = result.stdout.decode().strip()
    if not official(final):
        target.unlink(missing_ok=True)
        raise ValueError('Source redirected outside official government domains')
    return target.read_bytes(), final


def discover_links(body, current):
    soup = BeautifulSoup(body, 'html.parser')
    documents, pages = [], []
    result_page = bool(FORM.search(current) or (soup.title and FORM.search(soup.title.get_text(' ', strip=True))))
    for a in soup.select('a[href], option[value]'):
        raw = a.get('href', a.get('value', '')).strip().replace('\\', '/')
        if not raw or raw.startswith(('#', 'javascript:')):
            continue
        if a.name == 'option' and (raw.isdigit() or re.fullmatch(r'-*\s*select\b[^/.:?]*', raw, re.I)):
            continue
        url = quote(urldefrag(urljoin(current, raw))[0], safe=':/?=&%')
        if not official(url):
            continue
        label = a.get_text(' ', strip=True)
        context = label+' '+url+' '+a.get('title', '')
        row = a.find_parent('tr')
        if row:
            context += ' '+row.get_text(' ', strip=True)
        table = a.find_parent('table')
        if table and table.parent:
            context += ' '+' '.join(h.get_text(' ', strip=True) for h in table.parent.find_all(['h1','h2','h3','h4'], recursive=False))
        suffix = Path(urlparse(url).path).suffix.lower()
        document_link = suffix in ['.pdf', '.xls', '.xlsx', '.csv', '.zip'] or bool(re.search(r'/(?:ViewCMSFile|CMSFileView)$', urlparse(url).path, re.I))
        if FORM.search(context) or (result_page and suffix in ['.pdf', '.xls', '.xlsx', '.csv', '.zip']):
            item = {'url': url, 'label': label, 'discovered_on': current}
            (documents if document_link else pages).append(item)
        elif (PAGE.search(context) or label.strip().lower() in ['election', 'elections']) and suffix not in ['.pdf', '.xls', '.xlsx', '.zip', '.jpg', '.png', '.doc', '.docx'] and urlparse(url).hostname == urlparse(current).hostname:
            pages.append({'url': url, 'label': label, 'discovered_on': current})
    election_filter = soup.select_one('select#OCEO_ElectionDetails_ElectionFilterId')
    election_id = parse_qs(urlparse(current).query).get('id', [''])[0]
    if election_filter and election_id in ['1', '2'] and urlparse(current).path.lower() == '/electiondetails':
        for option in election_filter.select('option[value]'):
            value = option['value']
            if value.isdigit() and int(value) > 0:
                pages.append({'url': urljoin(current, '/electiondetails')+'?'+urlencode({'id': election_id, 'fltr': value}),
                              'label': option.get_text(' ', strip=True), 'discovered_on': current})
    if urlparse(current).hostname == 'ceo.sikkim.gov.in' and soup.select_one('select#Type'):
        scripts = ' '.join(script.get_text() for script in soup.select('script:not([src])'))
        if '/Election/Form20Details' in scripts:
            for election_id, kind in re.findall(r"EID:\s*'([0-9]+)',\s*Type:\s*\"(Assembly|Parlimentary)\"", scripts):
                pages.append({'url': urljoin(current, '/Election/Form20Details')+'?'+urlencode({'EID': election_id, 'Type': kind}),
                              'label': kind+' Form 20', 'discovered_on': current})
    for meta in soup.select('meta[http-equiv]'):
        if meta.get('http-equiv', '').lower() == 'refresh':
            match = re.search(r'url\s*=\s*[\"\']?([^\"\']+)', meta.get('content', ''), re.I)
            if match:
                target = urljoin(current, match[1].strip())
                if official(target):
                    pages.append({'url':target, 'label':'Official page redirect', 'discovered_on':current})
    return documents, pages


def crawl(entry, root, max_pages, rediscover=False):
    folder = root/entry['id']; folder.mkdir(parents=True, exist_ok=True)
    manifest_path = folder/'manifest.json'
    previous = json.loads(manifest_path.read_text(encoding='utf-8')) if manifest_path.exists() else {}
    pages = {p['url']: p for p in previous.get('pages', [])}
    documents = {d['url']: d for d in previous.get('documents', [])}
    pending = previous.get('pending_pages', []) or [{'url': entry['url'], 'label': entry['state']}]
    if rediscover:
        pending.extend(p for p in previous.get('pages', []) if not any(q['url'] == p['url'] for q in pending))
    pending.extend(e for e in previous.get('errors', []) if e.get('url') and not any(p['url'] == e['url'] for p in pending))
    pending.extend({'url':url,'label':'Verified supplementary official entry point'} for url in entry.get('seed_urls', []) if url not in pages and not any(p['url']==url for p in pending))
    errors = []
    visited = set()
    if manifest_path.exists():
        old = manifest_path.read_bytes()
        (folder/('manifest-'+hashlib.sha256(old).hexdigest()+'.json')).write_bytes(old)

    def checkpoint():
        result = entry | {'checked_at': datetime.now(timezone.utc).isoformat(), 'pages': list(pages.values()), 'documents': list(documents.values()), 'api_responses':previous.get('api_responses', []),
                          'pending_pages': list(pending), 'errors': list(errors), 'status': 'discovery_incomplete',
                          'note': 'Website discovery does not establish all-year or all-polling-station coverage. Missing links, dynamic pages and historical files require further review.'}
        temporary = folder/'manifest.tmp'
        temporary.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding='utf-8')
        temporary.replace(manifest_path)
        return result

    while pending and len(visited) < max_pages:
        current = pending.pop(0)
        url = current['url']
        if url in visited:
            continue
        visited.add(url)
        try:
            if url in pages and (folder/pages[url]['file']).exists():
                body = (folder/pages[url]['file']).read_bytes(); final = pages[url]['final_url']
                if hashlib.sha256(body).hexdigest() != pages[url]['sha256']:
                    raise ValueError('Saved page checksum changed')
            else:
                ajax = urlparse(url).hostname == 'ceo.sikkim.gov.in' and urlparse(url).path == '/Election/Form20Details'
                body, final = fetch(url, folder/'page.part', ajax=ajax)
                if body.startswith(b'%PDF-'):
                    documents[url] = current
                    continue
                if not re.search(b'<(?:html|table|body|a)[ >]', body, re.I):
                    raise ValueError('Page is not readable HTML')
                digest = hashlib.sha256(body).hexdigest(); name = digest+'.html'
                (folder/name).write_bytes(body)
                pages[url] = current | {'file': name, 'sha256': digest, 'final_url': final}
            found, links = discover_links(body, final)
            for document in found:
                documents.setdefault(document['url'], document)
            pending.extend(link for link in links if link['url'] not in visited and link['url'] not in pages and not any(p['url']==link['url'] for p in pending))
        except Exception as error:
            errors.append({'url': url, 'error': str(error)})
        finally:
            (folder/'page.part').unlink(missing_ok=True)
            checkpoint()
    for url, doc in documents.items():
        if doc.get('file') and (folder/doc['file']).is_file() and hashlib.sha256((folder/doc['file']).read_bytes()).hexdigest() == doc['sha256']:
            continue
        try:
            body, final = fetch(url, folder/'document.part')
            suffix = '.pdf' if body.startswith(b'%PDF-') else '.xlsx' if body.startswith(b'PK') and urlparse(final).path.lower().endswith('.xlsx') else '.zip' if body.startswith(b'PK') else '.xls' if body.startswith(bytes.fromhex('d0cf11e0a1b11ae1')) else None
            if not suffix:
                raise ValueError('Document signature requires review')
            digest = hashlib.sha256(body).hexdigest(); name = digest+suffix
            (folder/name).write_bytes(body)
            doc.update(file=name, sha256=digest, bytes=len(body), final_url=final, status='archived_requires_extraction')
        except Exception as error:
            doc.update(status='download_failed', error=str(error))
        finally:
            (folder/'document.part').unlink(missing_ok=True)
            checkpoint()
    result = checkpoint()
    print(entry['state']+': '+str(len(pages))+' pages; '+str(sum(bool(d.get('file')) for d in documents.values()))+' documents; '+str(len(pending))+' queued pages; '+str(len(errors))+' page errors', flush=True)
    return {k:v for k,v in result.items() if k not in ['pages', 'documents', 'pending_pages']} | {'documents': len(documents), 'archived_documents': sum(bool(d.get('file')) for d in documents.values()), 'pending_pages': len(pending)}


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--pages', type=int, default=30)
    parser.add_argument('--workers', type=int, choices=range(1,5), default=4)
    parser.add_argument('--state', action='append')
    parser.add_argument('--rediscover', action='store_true', help='Reparse saved pages after a discovery adapter update')
    args = parser.parse_args()
    root = Path(__file__).resolve().parents[1]/'application/storage/app/private/polling-station-sources'
    root.mkdir(parents=True, exist_ok=True)
    body, _ = fetch(DIRECTORY, root/'directory.json')
    (root/('directory-'+hashlib.sha256(body).hexdigest()+'.json')).write_bytes(body)
    soup = BeautifulSoup(json.loads(body)['cmsPagesData']['page_content'], 'html.parser')
    entries = []
    for option in soup.select('select[name="SelectURL"] option[value]'):
        url = option['value'].replace('http://', 'https://', 1)
        if official(url):
            state = option.get_text(' ', strip=True)
            entries.append({'id': hashlib.sha256(state.encode()).hexdigest()[:24], 'state': re.sub(r'\s+', ' ', state), 'url': url, 'official_directory_url': DIRECTORY})
    if len(entries) < 30:
        raise ValueError('Official directory is unexpectedly incomplete')
    supplements = json.loads((Path(__file__).resolve().parents[1]/'application/database/fixtures/polling-source-seeds.json').read_text(encoding='utf-8'))['states']
    for state, urls in supplements.items():
        entry = next((e for e in entries if e['state'] == state), None)
        if entry is None:
            entry = {'id':hashlib.sha256(state.encode()).hexdigest()[:24], 'state':state, 'url':urls[0], 'official_directory_url':urls[0]}
            entries.append(entry)
        entry['seed_urls'] = urls
    (root/'catalogue.json').write_text(json.dumps({'source_url': DIRECTORY, 'source_sha256': hashlib.sha256(body).hexdigest(), 'entries': entries}, indent=2), encoding='utf-8')
    with ThreadPoolExecutor(max_workers=args.workers) as pool:
        results = list(pool.map(lambda e: crawl(e, root, args.pages, args.rediscover), [e for e in entries if not args.state or e['state'] in args.state]))
    summary=[]
    for path in root.glob('*/manifest.json'):
        record=json.loads(path.read_text(encoding='utf-8'))
        summary.append({k:v for k,v in record.items() if k not in ['pages','documents','pending_pages']} |
                       {'documents':len(record['documents']), 'archived_documents':sum(bool(d.get('file')) for d in record['documents']), 'pending_pages':len(record['pending_pages'])})
    (root/'summary.json').write_text(json.dumps({'entries': summary}, ensure_ascii=False, indent=2), encoding='utf-8')
