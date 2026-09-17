"""Collect official catalogue editions without assuming historical constituency identities."""
import argparse
import concurrent.futures
import hashlib
import json
import re
import subprocess
from datetime import datetime, timezone
from pathlib import Path
from urllib.parse import urlparse, urljoin, urldefrag
from bs4 import BeautifulSoup


def key(url):
    return hashlib.sha256(url.encode()).hexdigest()[:24]


def fetch(url, path):
    parsed = urlparse(url)
    if parsed.scheme != 'https' or parsed.hostname not in ['old.eci.gov.in','www.eci.gov.in']:
        raise ValueError('This adapter accepts only official ECI hosts.')
    path.parent.mkdir(parents=True, exist_ok=True)
    result = subprocess.run(['curl.exe', '--silent', '--show-error', '--fail', '--location', '--proto', '=https', '--proto-redir', '=https', '--max-time', '50', '--max-filesize', '104857600', url, '-o', str(path)], capture_output=True)
    if result.returncode:
        path.unlink(missing_ok=True)
        raise ValueError('Official download failed (curl code ' + str(result.returncode) + ').')
    return path.read_bytes()


def report_pages(soup):
    return list(dict.fromkeys(a['href'].split('?')[0] for a in soup.select('.cDownloadsCategoryTable a[title^="View the file"]') if '/files/file/' in a.get('href','')))


def save_manifest(path, record):
    temp = path.with_suffix('.tmp')
    temp.write_text(json.dumps(record, indent=2, ensure_ascii=False) + '\n', encoding='utf-8')
    temp.replace(path)


def collect(kind, label, url, root):
    folder = root / key(url)
    folder.mkdir(parents=True, exist_ok=True)
    manifest = folder / 'manifest.json'
    old = json.loads(manifest.read_text(encoding='utf-8')) if manifest.exists() else {}
    record = dict(kind=kind, label=label, year=int(label[:4]), url=url, checked_at=datetime.now(timezone.utc).isoformat(), status='collecting', files=[], errors=[])
    save_manifest(manifest, record)
    if urlparse(url).hostname != 'old.eci.gov.in':
        existing = Path(__file__).parent / 'raw/elections/2024-detailed.pdf'
        if kind == 'pc' and int(label[:4]) == 2024 and existing.is_file() and existing.read_bytes().startswith(b'%PDF-'):
            data = existing.read_bytes()
            file = key(url) + '-saved.pdf'
            (folder / file).write_bytes(data)
            record['files'] = [dict(download_id=key(url)+'-saved', name='2024 saved official detailed results.pdf', file=file, bytes=len(data), sha256=hashlib.sha256(data).hexdigest(), source_page=url)]
            try:
                catalogue=json.loads(fetch('https://www.eci.gov.in/eci-backend/public/api/election-result?category_id=1',folder/'modern-catalogue.json'))
                summaries=[r for r in catalogue['results'] if r['title'].strip().startswith('32.') and 'Summary' in r['title']]
                if len(summaries)!=1:raise ValueError('Official summary catalogue entry missing or ambiguous')
                source_url=summaries[0]['pdf_zip_url']
                filename=key(url)+'-summary.pdf'
                data=fetch(source_url,folder/(filename+'.part'))
                if not data.startswith(b'%PDF-'):raise ValueError('Summary response is not a PDF')
                (folder/(filename+'.part')).replace(folder/filename)
                record['files'].append(dict(download_id=key(url)+'-summary',name='2024 Constituency Data Summary.pdf',file=filename,bytes=len(data),sha256=hashlib.sha256(data).hexdigest(),source_page=url,source_url=source_url))
                record.update(status='partial',errors=['Detailed and constituency-summary reports collected; other 2024 statistical reports remain outside this adapter.'])
            except Exception as error:
                for saved in old.get('files',[]):
                    candidate=folder/saved['file']
                    if saved['file']!=file and Path(saved['file']).name==saved['file'] and candidate.is_file() and hashlib.sha256(candidate.read_bytes()).hexdigest()==saved['sha256']:record['files'].append(saved)
                record.update(status='partial',errors=['Saved detailed report retained; summary download failed: '+str(error)])
        else:
            record.update(status='adapter_required', errors=['Modern ECI catalogue needs a separate download adapter; existing published reports remain available.'])
        save_manifest(manifest, record)
        return record
    previous = {f['download_id']: f for f in old.get('files', [])}
    try:
        if '/files/category/' in url:
            pending, visited, pages = [url], set(), []
            while pending:
                current = pending.pop(0)
                if current in visited:
                    continue
                if len(visited) >= 30:
                    raise ValueError('Category pagination exceeded the supported limit.')
                visited.add(current)
                html = fetch(current, folder / ('category-' + key(current) + '.html'))
                soup = BeautifulSoup(html, 'html.parser')
                pages.extend(report_pages(soup))
                pending.extend(a['href'] for a in soup.select('a[rel="next"]') if a.get('href', '').startswith(url) and a['href'] not in visited)
            pages = list(dict.fromkeys(pages))
        else:
            pages = [url]
        if not pages:
            raise ValueError('No report pages found; source layout needs review.')
        for page in pages:
            try:
                html = fetch(page + '?do=download', folder / ('page-' + key(page) + '.html'))
                soup = BeautifulSoup(html, 'html.parser')
                report_title = soup.title.get_text(' ',strip=True).replace(' - Election Commission of India','') if soup.title else label
                agreement = next((a for a in soup.select('a[href]') if a.get_text(' ', strip=True) == 'Agree & Download'), None)
                if agreement:
                    notice = soup.get_text(' ', strip=True)
                    permitted = 'Disclaimer :- These reports are developed on the basis of information provided by Chief Electoral Officers of States and UTs.'
                    if permitted not in notice and 'In case of any dispute, the data maintained in the Statutory Forms by the concerned Returning Officers shall prevail.' not in notice:
                        raise ValueError('Download notice changed; requires review.')
                    html = fetch(urljoin(page, agreement['href']), folder / ('response-' + key(page) + '.html'))
                    if html.startswith(b'%PDF-') or html.startswith(b'PK'):
                        extension = '.pdf' if html.startswith(b'%PDF-') else '.zip'
                        file = key(page) + '-direct' + extension
                        (folder / file).write_bytes(html)
                        record['files'].append(dict(download_id=key(page)+'-direct', name=report_title+extension, file=file, bytes=len(html), sha256=hashlib.sha256(html).hexdigest(), source_page=page))
                        save_manifest(manifest,record)
                        continue
                    soup = BeautifulSoup(html, 'html.parser')
                downloads = soup.select('a[data-action="download"]')
                if not downloads:
                    raise ValueError('No direct download links found; source layout or access notice needs review.')
                for link in downloads:
                    container = link.find_parent('li')
                    heading = container.select_one('h4') if container else None
                    filename = heading.get_text(' ', strip=True) if heading else 'Official report'
                    download_url = urljoin(page, link['href'])
                    match = re.search(r'(?:[?&])r=(\d+)', download_url)
                    download_id = key(page) + '-' + (match[1] if match else key(download_url))
                    try:
                        known = previous.get(download_id)
                        if known and (folder / known['file']).is_file() and hashlib.sha256((folder / known['file']).read_bytes()).hexdigest() == known['sha256']:
                            record['files'].append(known)
                            continue
                        temporary = folder / (download_id + '.part')
                        data = fetch(download_url, temporary)
                        extension = '.pdf' if data.startswith(b'%PDF-') else '.zip' if data.startswith(b'PK') else '.xls' if data.startswith(bytes.fromhex('d0cf11e0a1b11ae1')) else None
                        if extension is None:
                            temporary.unlink(missing_ok=True)
                            raise ValueError('Response was not a supported PDF, workbook or ZIP archive.')
                        if extension == '.zip' and filename.lower().endswith('.xlsx'):
                            extension = '.xlsx'
                        destination = folder / (download_id + extension)
                        temporary.replace(destination)
                        record['files'].append(dict(download_id=download_id, name=filename, file=destination.name, bytes=len(data), sha256=hashlib.sha256(data).hexdigest(), source_page=page))
                    except Exception as error:
                        record['errors'].append(dict(page=page, name=filename, reason=str(error)))
                save_manifest(manifest, record)
            except Exception as error:
                record['errors'].append(dict(page=page, reason=str(error)))
        record['status'] = 'collected' if record['files'] and not record['errors'] else 'partial' if record['files'] else 'failed'
    except Exception as error:
        record['errors'].append(str(error))
        record['status'] = 'partial' if record['files'] else 'failed'
    save_manifest(manifest, record)
    return record


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('catalogue')
    parser.add_argument('destination')
    parser.add_argument('--kind', choices=['ac','pc','all'], default='all')
    parser.add_argument('--year', type=int)
    args = parser.parse_args()
    catalogue = json.loads(Path(args.catalogue).read_text(encoding='utf-8'))
    jobs = [(kind,label,url,Path(args.destination)) for kind in ['ac','pc'] if args.kind in [kind,'all'] for label,url in catalogue[kind] if args.year is None or int(label[:4]) == args.year]
    with concurrent.futures.ThreadPoolExecutor(max_workers=4) as pool:
        futures = [pool.submit(collect,*job) for job in jobs]
        for future in concurrent.futures.as_completed(futures):
            result = future.result()
            print(f"{result['kind']} {result['label']}: {result['status']}; {len(result['files'])} files; {len(result['errors'])} issues", flush=True)
