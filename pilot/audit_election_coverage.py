"""Audit saved election editions; archive availability is not extraction completeness."""
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path


def polling_reference_summary(sources):
    """Count preserved references separately from distinct file contents."""
    by_hash = {}
    pdf_hashes = set()
    row_references = 0
    for source in sources:
        digest = source['sha256']
        rows = int(source.get('polling_rows') or 0)
        row_references += rows
        if digest in by_hash:
            counts = by_hash[digest]
            counts[0] = min(counts[0], rows)
            counts[1] = max(counts[1], rows)
        else:
            by_hash[digest] = [rows, rows]
        if source['file'].lower().endswith('.pdf'):
            pdf_hashes.add(digest)
    return {
        'documents': len(sources),
        'distinct_file_contents': len(by_hash),
        'duplicate_file_references': len(sources) - len(by_hash),
        'pdf_references': sum(source['file'].lower().endswith('.pdf') for source in sources),
        'distinct_pdf_contents': len(pdf_hashes),
        'source_rows': row_references,
        'source_rows_after_identical_file_dedup_min': sum(counts[0] for counts in by_hash.values()),
        'source_rows_after_identical_file_dedup_max': sum(counts[1] for counts in by_hash.values()),
        'files_with_different_extraction_counts': sum(counts[0] != counts[1] for counts in by_hash.values()),
        'counting_note': 'Documents and source rows are collection references, not unique elections, polling stations, or verified candidate results. The same file can be stored under multiple collection labels. The deduplicated row range only removes identical file contents; different extraction versions and overlapping editions require source review. Collection labels are not verified election jurisdictions.',
    }


def audit(root):
    fixtures = root/'application/database/fixtures'
    archive = root/'application/storage/app/private/election-archive'
    pc = json.loads((fixtures/'eci-election-archive.json').read_text(encoding='utf-8'))['pc']
    ac = json.loads((fixtures/'eci-assembly-national.json').read_text(encoding='utf-8'))['entries']
    entries = []
    for kind, sources in [('pc', [{'label': label, 'url': url, 'year': int(label[:4]), 'state': 'National report'} for label, url in pc]), ('ac', ac)]:
        for source in sources:
            folder = archive/hashlib.sha256(source['url'].encode()).hexdigest()[:24]
            manifest = json.loads((folder/'manifest.json').read_text(encoding='utf-8')) if (folder/'manifest.json').exists() else {}
            intact = bool(manifest.get('files')) and all((folder/f['file']).is_file() and hashlib.sha256((folder/f['file']).read_bytes()).hexdigest() == f['sha256'] for f in manifest.get('files', []))
            extracted = json.loads((folder/'extraction.json').read_text(encoding='utf-8')) if (folder/'extraction.json').exists() else {}
            records = extracted.get('records', [])
            entries.append({'kind': kind, 'state': source['state'], 'year': source['year'], 'label': source.get('label', str(source['year'])),
                            'source_url': source['url'], 'collection_status': manifest.get('status', 'missing'), 'source_checksums_verified': intact,
                            'constituency_tables': len(records), 'candidate_rows': sum(len(r.get('candidates', [])) for r in records),
                            'empty_candidate_tables': [{'code': r['code'], 'name': r['name'], 'note': r.get('error')} for r in records if not r.get('candidates')],
                            'records_with_notes': sum(r.get('status') == 'needs_review' for r in records)})
    bye = root/'application/storage/app/private/election-by-elections'
    catalogue = json.loads((bye/'catalogue.json').read_text(encoding='utf-8'))
    by_entries = []
    structured_path = bye/'structured/index.json'
    structured = json.loads(structured_path.read_text(encoding='utf-8')).get('records', []) if structured_path.exists() else []
    for entry in catalogue['entries']:
        path = bye/entry['id']/'manifest.json'
        record = json.loads(path.read_text(encoding='utf-8')) if path.exists() else {}
        by_entries.append(entry | {'status': record.get('status', entry['status']), 'files': len(record.get('files', [])),
                                  'raw_rows': sum(e['nonempty_rows'] for e in record.get('extractions', [])),
                                  'structured_results': sum(r.get('edition') == entry['id'] for r in structured), 'errors': record.get('errors', [])+record.get('extraction_errors', [])})
    polling_path = root/'application/storage/app/private/polling-station-sources/index.json'
    polling = json.loads(polling_path.read_text(encoding='utf-8')) if polling_path.exists() else {}
    output = {'checked_at': datetime.now(timezone.utc).isoformat(),
              'scope': 'Saved official catalogue editions, including overlapping and replacement editions. Counts must not be summed as unique elections. Collected sources are not a claim that every data field is extracted or verified.',
              'general_elections': entries, 'by_elections': by_entries,
              'polling_station_results': {'status': 'discovery_and_extraction_incomplete',
                  **polling_reference_summary(polling.get('sources', [])),
                  'note':polling.get('scope_note', 'PC/AC reports do not establish polling-station coverage. Separate official Form 20 and state/CEO archives require discovery and extraction.')}}
    destination = bye/'coverage.json'
    destination.write_text(json.dumps(output, ensure_ascii=False, indent=2), encoding='utf-8')
    (bye/'summary.json').write_text(json.dumps({'checked_at': output['checked_at'], 'entries': by_entries}, ensure_ascii=False, indent=2), encoding='utf-8')
    return output


if __name__ == '__main__':
    result = audit(Path(__file__).resolve().parents[1])
    print(json.dumps({'general_editions': len(result['general_elections']), 'by_election_entries': len(result['by_elections']),
                      'empty_candidate_tables': sum(len(e['empty_candidate_tables']) for e in result['general_elections'])}))
