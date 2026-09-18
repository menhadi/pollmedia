"""Collect public PC/AC general and by-election archive dropdowns and POST downloads."""
import hashlib
import json
from pathlib import Path
import re
from tempfile import TemporaryDirectory
from bs4 import BeautifulSoup
from collect_polling_sources import fetch

URL = 'https://ceo.nagaland.gov.in/election-archive'


def report_links(body):
    soup = BeautifulSoup(body, 'html.parser')
    output = []
    for form in soup.select('form'):
        identifier = form.select_one('input[name=id]')
        if identifier and form.select_one('button[name=download]') and str(identifier.get('value', '')).isdigit():
            label = form.parent.get_text(' ', strip=True)
            output.append({'id': identifier['value'], 'label': label})
    return output


def collect(root):
    folder = root/hashlib.sha256(b'NAGALAND').hexdigest()[:24]
    path = folder/'nagaland-supplement.json'
    data = json.loads(path.read_text(encoding='utf-8')) if path.exists() else {'documents': [], 'api_responses': []}
    docs = {d['request_id']: d for d in data['documents']}
    responses = {r['request_id']: r for r in data['api_responses']}
    data.update(errors=[], status='collecting')

    def save():
        data.update(documents=list(docs.values()), api_responses=list(responses.values()))
        temporary = path.with_suffix('.tmp')
        temporary.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding='utf-8')
        temporary.replace(path)

    with TemporaryDirectory() as temporary:
        cookies = Path(temporary)/'cookies'
        initial, _ = fetch(URL, folder/'nagaland-page.part', cookies=cookies)
        token = re.search(rb"csrfmiddlewaretoken:\s*'([^']+)'", initial)[1].decode()

        def request(fields):
            identity = hashlib.sha256(json.dumps(fields, sort_keys=True).encode()).hexdigest()
            if identity in responses:
                saved = responses[identity]; body = (folder/saved['file']).read_bytes()
                if hashlib.sha256(body).hexdigest() != saved['sha256']:
                    raise ValueError('Preserved response checksum mismatch')
            else:
                body, _ = fetch(URL, folder/'nagaland-page.part', fields | {'csrfmiddlewaretoken': token}, cookies=cookies, referer=URL)
                digest = hashlib.sha256(body).hexdigest(); filename = digest+('.json' if body.startswith(b'{') else '.html')
                (folder/filename).write_bytes(body)
                responses[identity] = {'request_id': identity, 'url': URL, 'method': 'POST', 'form': fields, 'file': filename, 'sha256': digest}
            save()
            return body

        for category in ['LS', 'NLA']:
            for kind in ['G', 'B']:
                fields = {'election_category': category, 'election_type': kind}
                try:
                    years = json.loads(request(fields | {'get_year': ''}))['yearlist']
                    for raw_year in years:
                        year = str(raw_year[0] if isinstance(raw_year, list) else raw_year)
                        if not re.fullmatch(r'(?:19|20)\d{2}', year):
                            raise ValueError('Unexpected archive year')
                        try:
                            reports = report_links(request(fields | {'year': year, 'election_archive': ''}))
                            for report in reports:
                                key = hashlib.sha256(('report:'+report['id']).encode()).hexdigest()
                                docs.setdefault(key, {'request_id': key, 'url': URL, 'discovered_on': URL, 'source_record_id': report['id'],
                                    'label': category+' '+kind+' '+year+' · '+report['label']+' · Source record '+report['id'], 'year': int(year), 'category': category, 'election_type': kind,
                                    'download_method': 'POST', 'download_form': {'id': report['id'], 'download': ''}})
                            print(category+' '+kind+' '+year+': '+str(len(reports))+' reports', flush=True)
                        except Exception as error:
                            data['errors'].append({'url': URL, 'year': year, 'error': str(error)})
                        save()
                except Exception as error:
                    data['errors'].append({'url': URL, **fields, 'error': str(error)}); save()
        for item in docs.values():
            if item.get('file') and (folder/item['file']).is_file() and hashlib.sha256((folder/item['file']).read_bytes()).hexdigest() == item['sha256']:
                continue
            try:
                body, final = fetch(URL, folder/'document-nagaland.part', item['download_form'] | {'csrfmiddlewaretoken': token}, cookies=cookies, referer=URL)
                if not body.startswith(b'%PDF-'):
                    raise ValueError('Archive download did not return a PDF')
                digest = hashlib.sha256(body).hexdigest(); filename = digest+'.pdf'
                (folder/filename).write_bytes(body)
                item.update(file=filename, sha256=digest, bytes=len(body), final_url=final, status='archived_requires_extraction')
            except Exception as error:
                item.update(status='download_failed', error=str(error))
            finally:
                (folder/'document-nagaland.part').unlink(missing_ok=True)
            save()
    (folder/'nagaland-page.part').unlink(missing_ok=True)
    data['status'] = 'collected_with_coverage_notes'; save()
    print(json.dumps({'reports': len(docs), 'preserved': sum(bool(d.get('file')) for d in docs.values())}), flush=True)


if __name__ == '__main__':
    collect(Path(__file__).resolve().parents[1]/'application/storage/app/private/polling-station-sources')
