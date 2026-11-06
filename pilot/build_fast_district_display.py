"""Build lighter navigation geometry without modifying original SOI/LGD files."""
import argparse
import collections
import hashlib
import json
from pathlib import Path
from shapely.geometry import mapping, shape


def build(root):
    maps = root / 'application/public/maps'
    source = maps / 'india-district-display.json'
    data = json.loads(source.read_text(encoding='utf-8'))
    urls = json.loads((root / 'application/database/fixtures/district-dashboard-urls.json').read_text(encoding='utf-8'))
    data['subdistrict_by_district'] = {}
    for state, filename in data.get('subdistrict_files', {}).items():
        groups = collections.defaultdict(list)
        for feature in json.loads((maps / filename).read_text(encoding='utf-8'))['features']:
            groups[str(feature['properties']['district_code'])].append(feature)
        for district, features in groups.items():
            raw = json.dumps({'type': 'FeatureCollection', 'features': features}, separators=(',', ':'))
            filename = f'india-subdistrict-fast-{state}-{district}-{hashlib.sha256(raw.encode()).hexdigest()[:10]}.json'
            (maps / filename).write_text(raw, encoding='utf-8')
            data['subdistrict_by_district'][f'{state}:{district}'] = filename
    for feature in data['features']:
        feature['geometry'] = mapping(shape(feature['geometry']).simplify(.008, preserve_topology=True))
        feature['properties'].pop('source_properties', None)
    paths = {f"/india/districts/{row['state']}/{row['district']}/dashboard": row['path'] for row in urls.values()}
    for district in data['dashboards'].values():
        for link in district['navigation']:
            link['label'] = link['label'].replace(' · Census 2011', '')
            for old, new in paths.items():
                if link['url'].endswith(old):
                    link['url'] = 'https://pollmedia.org' + new
                    break
    data['display_derivation'] = {'source_sha256': hashlib.sha256(source.read_bytes()).hexdigest(), 'simplification_degrees': .008, 'purpose': 'Display only. Original source geometries and identifiers remain unchanged.'}
    (maps / 'india-district-fast.json').write_text(json.dumps(data, separators=(',', ':')), encoding='utf-8')


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--root', type=Path, default=Path(__file__).resolve().parents[1])
    build(parser.parse_args().root)
