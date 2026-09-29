"""Package existing PDF candidates; never extract text or accept indicators."""
import argparse
import hashlib
import json
from pathlib import Path


def sha(path):
    digest = hashlib.sha256()
    with path.open('rb') as stream:
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            digest.update(chunk)
    return digest.hexdigest()


def arithmetic_flags(row):
    flags = []
    total = row.get('total_population')
    components = [row.get(k) for k in ('scheduled_castes_population', 'scheduled_tribes_population')]
    for name in ('total_population', 'scheduled_castes_population', 'scheduled_tribes_population'):
        value = row.get(name)
        if value is not None and (type(value) is not int or value < 0):
            flags.append(name + '_invalid_nonnegative_integer')
    if not flags and total is not None:
        if any(value is not None and value > total for value in components):
            flags.append('component_exceeds_total')
        if all(value is not None for value in components) and sum(components) > total:
            flags.append('sc_plus_st_exceeds_total')
    return flags


def prepare(root, output):
    evidence = root / 'source-evidence'
    output.mkdir(exist_ok=False)
    sources, seen, counts, failures = {}, set(), {}, 0
    target = output / 'candidates.jsonl'
    with target.open('w', encoding='utf-8') as stream:
        for report_path in sorted(evidence.glob('table-*.report.json')):
            report = json.loads(report_path.read_text())
            rows_path = report_path.with_name(report_path.name.replace('.report.json', '.jsonl'))
            assert sha(rows_path) == report['rows_sha256'], 'Candidate checksum mismatch'
            original = report['original_sha256']
            if original not in sources:
                manifest_path = evidence / f'pdf-{original}.manifest.json'
                manifest = json.loads(manifest_path.read_text())
                original_path = root / 'packages' / manifest['original_file']
                assert original_path.resolve().is_relative_to((root / 'packages').resolve())
                assert sha(original_path) == original, 'Original checksum mismatch'
                pages_path = evidence / f'pdf-{original}.pages.jsonl'
                assert sha(pages_path) == manifest['pages_jsonl_sha256']
                pages = {}
                with pages_path.open() as pages_stream:
                    for line in pages_stream:
                        page = json.loads(line)
                        assert hashlib.sha256(page['text'].encode()).hexdigest() == page['text_sha256']
                        pages[page['page']] = page
                sources[original] = {'manifest': manifest, 'manifest_sha256': sha(manifest_path),
                                     'original_path': str(original_path), 'pages': pages}
            source = sources[original]
            count = 0
            with rows_path.open() as rows_stream:
                for line in rows_stream:
                    row = json.loads(line)
                    assert row['original_sha256'] == original
                    assert row['source_url'] == source['manifest']['source_url']
                    page = source['pages'][row['page']]
                    assert page['text_sha256'] == row['page_text_sha256']
                    assert page['text'].splitlines()[row['line'] - 1].strip() == row['raw_line'].strip()
                    locator = (original, row['page'], row['line'])
                    assert locator not in seen, 'Repeated physical source locator'
                    seen.add(locator)
                    flags = arithmetic_flags(row)
                    failures += bool(flags)
                    record = {'candidate': row, 'publication_label': 'PENDING ADMIN REVIEW',
                              'arithmetic_flags': flags, 'arithmetic_scope': 'Nonnegative integers; reported SC/ST bounds only. Missing values remain missing. No visual/header/geographic confirmation.',
                              'input_file': rows_path.name, 'input_sha256': report['rows_sha256']}
                    stream.write(json.dumps(record, ensure_ascii=False) + '\n')
                    count += 1
            assert count == report['structured_pdf_rows']
            counts[rows_path.name] = count
    references = {key: {k: v for k, v in value.items() if k != 'pages'} for key, value in sources.items()}
    receipt = {'state': 'prepared_pending_admin_review_not_published', 'rows': sum(counts.values()),
               'counts': counts, 'rows_with_arithmetic_flags': failures, 'sources': references,
               'candidates_sha256': sha(target), 'integrity': 'Original, candidate and page hashes; every page/line witness; unique physical locators verified',
               'limits': 'Incomplete PDF subset; not approved indicators or national completeness; no current geography joins'}
    (output / 'manifest.json').write_text(json.dumps(receipt, indent=2), encoding='utf-8')
    return receipt


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('root', type=Path)
    parser.add_argument('output', type=Path)
    args = parser.parse_args()
    print(json.dumps(prepare(args.root, args.output), indent=2))
