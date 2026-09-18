"""Preserve results from the public CEO Puducherry election dropdown endpoints."""
import hashlib
import json
from pathlib import Path
from urllib.parse import urljoin, urlparse
from bs4 import BeautifulSoup
from collect_polling_sources import fetch, official, crawl

BASE = 'https://ceopuducherry.py.gov.in/'
PAGE = BASE+'stationwise_votes_polled.php'


def collect(root):
    catalogue = json.loads((root/'catalogue.json').read_text(encoding='utf-8'))
    entry = next(e for e in catalogue['entries'] if e['state'] == 'PUDUCHERRY')
    folder = root/entry['id']
    manifest_path = folder/'manifest.json'
    previous = manifest_path.read_bytes()
    manifest = json.loads(previous)
    (folder/('manifest-'+hashlib.sha256(previous).hexdigest()+'.json')).write_bytes(previous)
    responses = {r['request_id']:r for r in manifest.get('api_responses', [])}
    documents = {d['url']:d for d in manifest['documents']}

    def request(endpoint, form):
        url = BASE+endpoint
        key = hashlib.sha256((url+json.dumps(form,sort_keys=True)).encode()).hexdigest()
        if key in responses:
            body = (folder/responses[key]['file']).read_bytes()
            if hashlib.sha256(body).hexdigest() != responses[key]['sha256']:
                raise ValueError('Saved dropdown response checksum changed')
        else:
            body, final = fetch(url, folder/'api.part', form)
            digest = hashlib.sha256(body).hexdigest()
            name = digest+'.html'
            (folder/name).write_bytes(body)
            (folder/'api.part').unlink(missing_ok=True)
            responses[key] = {'request_id':key, 'url':url, 'method':'POST', 'form':form,
                              'file':name, 'sha256':digest, 'final_url':final, 'discovered_on':PAGE}
        return BeautifulSoup(body, 'html.parser')

    # These values and endpoints are supplied by the official page and its JS.
    for election in ['Election to Puducherry Legislative Assembly', 'Election to Lok Sabha']:
        for kind in ['General Elections', 'Bye Elections']:
            fields = {'election':election, 'ele_type':kind}
            options = request('eleyearstation.php', fields)
            for option in options.select('option[value]'):
                year = option['value']
                if not year.isdigit() or not 1950 <= int(year) <= 2100:
                    continue
                listing = request('stationwisevotespdf.php', fields | {'election_year':year})
                for a in listing.select('a[href]'):
                    url = urljoin(BASE, a['href'])
                    if official(url) and Path(urlparse(url).path).suffix.lower() in ['.pdf','.xls','.xlsx','.zip']:
                        documents.setdefault(url, {'url':url, 'label':election+' · '+kind+' · '+year+' · '+a.get_text(' ',strip=True),
                                                   'discovered_on':PAGE, 'year':int(year), 'election_type':kind})
    manifest['api_responses'] = list(responses.values())
    manifest['documents'] = list(documents.values())
    temporary = manifest_path.with_suffix('.tmp')
    temporary.write_text(json.dumps(manifest,ensure_ascii=False,indent=2),encoding='utf-8')
    temporary.replace(manifest_path)
    print('Preserved '+str(len(responses))+' dropdown responses; '+str(len(documents))+' document links.',flush=True)
    crawl(entry, root, 0)


if __name__ == '__main__':
    collect(Path(__file__).resolve().parents[1]/'application/storage/app/private/polling-station-sources')
