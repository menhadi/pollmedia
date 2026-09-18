"""Preserve Haryana's public historical booth-result dropdown responses and files."""
import hashlib
import json
from pathlib import Path
from urllib.parse import quote, urlparse
from bs4 import BeautifulSoup
from collect_polling_sources import fetch

BASE = 'https://ceoharyana.gov.in'
PAGE = BASE+'/WebCMS/Start/1449'


def report(record):
    name = record['FileName'].replace('\\', '/')
    if name.startswith('/') or '..' in name.split('/') or ':' in name or '?' in name or '#' in name:
        raise ValueError('Unexpected report filename')
    url = BASE+'/BoothWiseResult/'+quote(name, safe='/')
    return {'url': url, 'discovered_on': PAGE, 'source_record_id': record['Id'],
            'label': ' · '.join(str(record.get(k) or '') for k in ['YearName', 'ElectionTypeName', 'ElectionSubTypeName', 'DistrictName', 'AssemblyConstituencyName']),
            'year': record['YearName'], 'source_metadata': record}


def collect(root):
    folder = root/hashlib.sha256(b'HARYANA').hexdigest()[:24]
    path = folder/'haryana-supplement.json'
    data = json.loads(path.read_text(encoding='utf-8')) if path.exists() else {'documents': [], 'api_responses': []}
    docs = {d['url']: d for d in data['documents']}
    responses = {r['request_id']: r for r in data['api_responses']}
    data.update(errors=[], status='collecting')

    def save():
        data.update(documents=list(docs.values()), api_responses=list(responses.values()))
        temporary = path.with_suffix('.tmp')
        temporary.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding='utf-8')
        temporary.replace(path)

    def request(url, fields=None):
        key = hashlib.sha256((url+json.dumps(fields, sort_keys=True)).encode()).hexdigest()
        if key in responses:
            saved = responses[key]
            body = (folder/saved['file']).read_bytes()
            if hashlib.sha256(body).hexdigest() != saved['sha256']:
                raise ValueError('Saved dropdown checksum changed')
        else:
            body, final = fetch(url, folder/'haryana-api.part', fields, ajax=fields is not None)
            digest = hashlib.sha256(body).hexdigest()
            filename = digest+('.json' if fields is not None else '.html')
            (folder/filename).write_bytes(body)
            responses[key] = {'request_id': key, 'url': url, 'method': 'POST' if fields is not None else 'GET',
                              'form': fields, 'file': filename, 'sha256': digest, 'final_url': final}
            save()
        return body

    soup = BeautifulSoup(request(PAGE), 'html.parser')
    for option in soup.select('select#ElectionType option[value]'):
        kind = option['value']
        if not kind.isdigit() or int(kind) == 0:
            continue
        try:
            years = json.loads(request(BASE+'/WebCMS/GetYearListByElectionType', {'ElectionType': kind}))
            for year in years:
                try:
                    records = json.loads(request(BASE+'/WebCMS/FindBoothWiseResult', {'electionType': kind, 'yearId': str(year['YearId'])}))
                    if not isinstance(records, list):
                        raise ValueError('Unexpected results response')
                    for record in records:
                        item = report(record)
                        docs.setdefault(item['url'], item)
                    print(option.get_text(' ', strip=True)+' '+year['YearName']+': '+str(len(records))+' reports', flush=True)
                except Exception as error:
                    data['errors'].append({'election_type': kind, 'year': year['YearName'], 'error': str(error)})
                save()
        except Exception as error:
            data['errors'].append({'election_type': kind, 'error': str(error)}); save()
    for item in docs.values():
        if item.get('file') and (folder/item['file']).exists():
            with (folder/item['file']).open('rb') as stream:
                if hashlib.file_digest(stream, 'sha256').hexdigest() == item['sha256']:
                    continue
        try:
            body, final = fetch(item['url'], folder/'document-haryana.part')
            suffix = '.pdf' if body.startswith(b'%PDF-') else '.xls' if body.startswith(bytes.fromhex('d0cf11e0a1b11ae1')) else '.xlsx' if body.startswith(b'PK') and urlparse(final).path.lower().endswith('.xlsx') else None
            if suffix is None:
                raise ValueError('Report signature requires review')
            digest = hashlib.sha256(body).hexdigest(); filename = digest+suffix
            (folder/filename).write_bytes(body)
            item.update(file=filename, sha256=digest, bytes=len(body), final_url=final, status='archived_requires_extraction')
            item.pop('error', None)
        except Exception as error:
            item.update(status='download_failed', error=str(error))
        finally:
            (folder/'document-haryana.part').unlink(missing_ok=True)
        save()
    (folder/'haryana-api.part').unlink(missing_ok=True)
    data['status'] = 'collected_with_coverage_notes'; save()
    print(json.dumps({'reports': len(docs), 'preserved': sum(bool(d.get('file')) for d in docs.values()), 'selection_errors': len(data['errors'])}), flush=True)


if __name__ == '__main__':
    collect(Path(__file__).resolve().parents[1]/'application/storage/app/private/polling-station-sources')
