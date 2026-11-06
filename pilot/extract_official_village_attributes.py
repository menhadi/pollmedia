"""Extract identity fields from pinned official archives without changing geometry."""
import hashlib
import codecs
import json
import re
import zipfile
from pathlib import Path


def geojson_features(stream):
    decoder = json.JSONDecoder()
    utf8 = codecs.getincrementaldecoder('utf-8')()
    buffer = ''
    while True:
        chunk = stream.read(65536)
        if not chunk:
            raise ValueError('Original archive has no features array')
        buffer += utf8.decode(chunk)
        match = re.search(r'"features"\s*:\s*\[', buffer)
        if match:
            buffer = buffer[match.end():]
            break
    while True:
        buffer = buffer.lstrip()
        if buffer.startswith(']'):
            return
        if buffer.startswith(','):
            buffer = buffer[1:].lstrip()
        try:
            feature, end = decoder.raw_decode(buffer)
        except json.JSONDecodeError:
            chunk = stream.read(65536)
            if not chunk:
                raise ValueError('Truncated original GeoJSON feature')
            buffer += utf8.decode(chunk)
            continue
        if feature.get('type') != 'Feature':
            raise ValueError('Unexpected original GeoJSON object')
        buffer = buffer[end:]
        yield feature


def extract(archive, expected_sha, output):
    if hashlib.sha256(archive.read_bytes()).hexdigest() != expected_sha:
        raise ValueError('Official archive edition differs from its retained source hash')
    with zipfile.ZipFile(archive) as source:
        names = [name for name in source.namelist() if name.lower().endswith(('.geojson', '.json'))]
        if len(names) != 1:
            raise ValueError('A unique original GeoJSON is required')
        with source.open(names[0]) as stream, output.open('w', encoding='utf-8') as target:
            for index, feature in enumerate(geojson_features(stream)):
                original = feature['properties']
                fields = {key: original.get(key) for key in ['id', 'objectid_1', 'objectid', 'stcode', 'dtcode', 'sdcode', 'vlcode', 'village', 'subdistric', 'district', 'total_urban_rural\n']}
                target.write(json.dumps({'record': index + 1, 'fields': fields}, ensure_ascii=False) + '\n')
    return hashlib.sha256(output.read_bytes()).hexdigest()
