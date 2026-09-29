"""Read-only direct-source backlog, including registrations not yet seen by cron."""
import argparse
import json
from pathlib import Path


def read(path, default):
    return json.loads(path.read_text(encoding='utf-8')) if path.exists() else default


def summarize(root):
    registry = read(root / 'direct-pdf-sources.json', [])
    csv_registry = read(root / 'direct-csv-sources.json', [])
    csv_urls = {item['source_url'] for item in csv_registry}
    registry += csv_registry
    state = read(root / 'feeder-status.json', {})
    downloads = state.get('downloads', {})
    rows = []
    seen = set()
    for item in registry:
        url = item['source_url']
        if url in seen:
            continue
        seen.add(url)
        record = (state.get('csv_downloads', {}) if url in csv_urls else downloads).get(url)
        phase = 'registered_pending_admission'
        if record is not None:
            phase = 'download_retry_pending' if record.get('error') else 'download_pending'
            if record.get('access_review_required'):
                phase = 'access_review_required'
            if record.get('complete'):
                phase = 'downloaded_text_pending'
                if url in csv_urls:
                    phase = 'acquired_pending_csv_validation'
                digest = record.get('sha256', '')
                if len(digest) != 64 or any(c not in '0123456789abcdef' for c in digest):
                    phase = 'download_receipt_invalid'
                else:
                    manifest = root / 'source-evidence' / f'pdf-{digest}.manifest.json'
                    if url not in csv_urls and manifest.exists():
                        metadata = read(manifest, {})
                        phase = ('text_evidence_present_pending_review'
                                 if metadata.get('original_sha256') == digest else 'text_receipt_mismatch')
        rows.append(dict(source_url=url, academic_year=item.get('academic_year'),
                         structure=item.get('structure'), phase=phase,
                         error=(record or {}).get('error')))
    # HTML snapshots are registered by queue descriptor, not the download registries.
    seen_html = set()
    for descriptor in sorted((root / 'queue').glob('html-*.json')):
        job = read(descriptor, {})
        if job.get('kind') != 'mgnrega_html':
            continue
        digest = job.get('sha256', '')
        url = job.get('source_url')
        identity = (url, digest)
        if identity in seen_html:
            continue
        seen_html.add(identity)
        phase = 'html_queued_pending_extraction'
        package = job.get('package', '')
        if (len(digest) != 64 or any(c not in '0123456789abcdef' for c in digest)
                or not package or Path(package).name != package or not package.endswith('.html')):
            phase = 'html_descriptor_invalid'
        elif not (root / 'packages' / package).is_file():
            phase = 'html_original_missing'
        else:
            manifest = root / 'source-evidence' / f'mgnrega-html-{digest}.manifest.json'
            if manifest.exists():
                phase = ('html_evidence_present_pending_review'
                         if read(manifest, {}).get('original_sha256') == digest
                         else 'html_receipt_mismatch')
        rows.append(dict(source_url=url, original_sha256=digest, kind='mgnrega_html',
                         phase=phase, descriptor=descriptor.name))
    counts = {}
    for row in rows:
        counts[row['phase']] = counts.get(row['phase'], 0) + 1
    return dict(worker_status=read(root / 'status.json', {}),
                registered_unique=len(rows), counts=counts, sources=rows,
                limitation='Receipt presence only; not payload validation, OCR completion, approved indicators or publication.')


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('root', type=Path)
    print(json.dumps(summarize(parser.parse_args().root), indent=2))
