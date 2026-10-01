"""Build a verified, source-separated 1901/1911 A-02 import package."""
import argparse
import hashlib
import json
from pathlib import Path
import re
import zipfile

from extract_historical_a02 import extract


def build(root, destination, years=(1901, 1911), originals_root=None):
    if tuple(years) not in ((1901, 1911), (1921,), (1931,), (1941,), (1951,), (1961,)):
        raise ValueError('Unsupported historical release years')
    originals_root = originals_root or root
    suffix = '-'.join(map(str, years))
    if destination.exists():
        raise FileExistsError('Preserve existing package')
    members, sources = {}, []
    for payload in sorted(root.glob('*.'+suffix+'.json')):
        ident = payload.name.split('.')[0]
        if not re.fullmatch(r'433(?:3[3-9]|[4-5][0-9]|6[0-8])', ident):
            raise ValueError('Source not in verified A-02 inventory')
        data = json.loads(payload.read_text(encoding='utf-8'))
        manifest = json.loads((originals_root/f'{ident}.manifest.json').read_text())
        originals = list(originals_root.glob(f'{ident}.xls*'))
        if len(originals) != 1:
            raise ValueError('Original identity is ambiguous')
        original = originals[0]
        fresh = extract(original, manifest, years=years)
        if fresh['records'] != data['records'] or fresh['raw_rows'] != data['raw_rows']:
            raise ValueError('Extraction differs from original: '+ident)
        if not manifest['landing'].endswith('/catalog/'+ident) or not manifest['url'].startswith(
                'https://censusindia.gov.in/nada/index.php/catalog/'+ident+'/download/'):
            raise ValueError('Official provenance mismatch')
        raw_name = 'originals/'+original.name
        extracted_name = 'extracted/'+payload.name
        members[raw_name] = original.read_bytes()
        members[extracted_name] = payload.read_bytes()
        for year in years:
            rows = [r for r in data['records'] if r['year'] == year]
            sources.append(dict(key=f'census-a02-{ident}-{year}', catalogue=ident, year=year,
                                original=raw_name, extracted=extracted_name,
                                source_url=manifest['url'], landing_url=manifest['landing'],
                                original_sha256=manifest['sha256'], row_count=len(rows),
                                scope='Retrospective A-02 population adjusted to 2011 jurisdictions; preserve source footnotes.'))
    if not sources:
        raise ValueError('No selected records')
    package = dict(version=1, family='historical-census-a02', selected_years=list(years),
                   sources=sources, files={k: hashlib.sha256(v).hexdigest() for k, v in members.items()},
                   limitation='Source-specific rows overlap. Not original census-era geography, education, health or national completeness. Database publication requires tested historical importer.')
    destination.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(destination, 'x', zipfile.ZIP_DEFLATED) as archive:
        for name, raw in members.items():
            archive.writestr(name, raw)
        archive.writestr('manifest.json', json.dumps(package, indent=2))
    digest = hashlib.sha256(destination.read_bytes()).hexdigest()
    destination.with_suffix('.sha256').write_text(digest+'  '+destination.name+'\n', encoding='ascii')
    return dict(sha256=digest, partitions=len(sources), source_records=sum(s['row_count'] for s in sources))


if __name__ == '__main__':
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('root', type=Path)
    parser.add_argument('destination', type=Path)
    args = parser.parse_args()
    print(json.dumps(build(args.root, args.destination)))
