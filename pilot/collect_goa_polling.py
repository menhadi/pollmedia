"""Preserve all election selections exposed by Goa's public Form 20 dropdown."""
import hashlib
import json
from pathlib import Path
from urllib.parse import urljoin
import requests
from bs4 import BeautifulSoup
from collect_polling_sources import fetch, official

URL = 'https://ceogoa.nic.in/appln/UIL/Form20n21.aspx?UOlevK=Qb25UcGilDg='


def links(body):
    soup = BeautifulSoup(body, 'html.parser')
    output = []
    for anchor in soup.select('table a[href*="Form20n21Viewer.aspx"]'):
        url = urljoin(URL, anchor['href'])
        if official(url):
            output.append({'url': url, 'label': anchor.find_parent('tr').get_text(' ', strip=True), 'discovered_on': URL})
    return output


def collect(root):
    entries = json.loads((root/'catalogue.json').read_text(encoding='utf-8'))['entries']
    folder = root/next(e['id'] for e in entries if e['state'] == 'GOA')
    path = folder/'goa-supplement.json'
    data = json.loads(path.read_text(encoding='utf-8')) if path.exists() else {'documents': [], 'api_responses': [], 'errors': []}
    docs = {d['url']: d for d in data['documents']}
    responses = {r['request_id']: r for r in data['api_responses']}
    data['errors'] = []
    data['status'] = 'collecting'

    def save():
        data.update(documents=list(docs.values()), api_responses=list(responses.values()))
        temp = path.with_suffix('.tmp')
        temp.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding='utf-8')
        temp.replace(path)

    session = requests.Session()
    response = session.get(URL, timeout=(15, 40)); response.raise_for_status()
    if not official(response.url):
        raise ValueError('Unexpected source redirect')
    digest = hashlib.sha256(response.content).hexdigest()
    (folder/(digest+'.html')).write_bytes(response.content)
    responses['directory'] = {'request_id': 'directory', 'file': digest+'.html', 'sha256': digest, 'url': URL, 'method': 'GET'}
    initial = BeautifulSoup(response.content, 'html.parser')
    options = [(o['value'], o.get_text(' ', strip=True)) for o in initial.select('select[name="ctl00$Main$drpElection"] option[value]')]
    if not options:
        raise ValueError('Official election dropdown is missing')
    data['available_elections'] = [{'value': value, 'label': label} for value, label in options]
    for value, label in options:
        request_id = hashlib.sha256((URL+value).encode()).hexdigest()
        try:
            if request_id in responses:
                entry = responses[request_id]
                body = (folder/entry['file']).read_bytes()
                if hashlib.sha256(body).hexdigest() != entry['sha256']:
                    raise ValueError('Saved dropdown response checksum mismatch')
            else:
                response = session.get(URL, timeout=(15, 40)); response.raise_for_status()
                soup = BeautifulSoup(response.content, 'html.parser')
                fields = {i['name']: i.get('value', '') for i in soup.select('input[type=hidden][name]')}
                fields.update({'ctl00$Main$drpElection': value, 'ctl00$Main$drpAC': '0', 'ctl00$Main$btniSearch': 'Search'})
                response = session.post(URL, data=fields, timeout=(15, 40)); response.raise_for_status()
                if not official(response.url):
                    raise ValueError('Unexpected source redirect')
                body = response.content
                digest = hashlib.sha256(body).hexdigest(); filename = digest+'.html'
                (folder/filename).write_bytes(body)
                responses[request_id] = {'request_id': request_id, 'file': filename, 'sha256': digest, 'url': URL,
                                         'method': 'POST', 'election': value, 'label': label}
            selected = BeautifulSoup(body, 'html.parser').select_one('select[name="ctl00$Main$drpElection"] option[selected]')
            if selected is None or selected.get('value') != value:
                responses.pop(request_id, None)
                raise ValueError('Returned election selection does not match the requested source year')
            found = links(body)
            responses[request_id]['document_links'] = len(found)
            for item in found:
                docs.setdefault(item['url'], item | {'election': value, 'election_label': label})
            print(label+': '+str(len(found))+' source links', flush=True)
        except Exception as error:
            data['errors'].append({'url': URL, 'election': value, 'error': str(error)})
        save()
    for item in docs.values():
        if item.get('file') and (folder/item['file']).is_file() and hashlib.sha256((folder/item['file']).read_bytes()).hexdigest() == item['sha256']:
            continue
        try:
            body, final = fetch(item['url'], folder/'document-goa.part')
            if not body.startswith(b'%PDF-'):
                raise ValueError('Viewer did not return a PDF')
            digest = hashlib.sha256(body).hexdigest(); filename = digest+'.pdf'
            (folder/filename).write_bytes(body)
            item.update(file=filename, sha256=digest, bytes=len(body), final_url=final, status='archived_requires_extraction')
        except Exception as error:
            item.update(status='download_failed', error=str(error))
        finally:
            (folder/'document-goa.part').unlink(missing_ok=True)
        save()
    data['status'] = 'collected_with_coverage_notes'
    save()
    print(json.dumps({'elections': len(options), 'documents': len(docs), 'preserved': sum(bool(d.get('file')) for d in docs.values())}), flush=True)


if __name__ == '__main__':
    collect(Path(__file__).resolve().parents[1]/'application/storage/app/private/polling-station-sources')
