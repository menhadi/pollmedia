"""Package the preserved official Panchkula workbook without inventing geographic links."""
import hashlib
import json
import zipfile
from datetime import datetime, timezone
from pathlib import Path

import openpyxl

ROOT = Path(__file__).resolve().parents[1]
KEY = 'haryana-panchkula-pca-2011'
URL = 'https://censusindia.gov.in/nada/index.php/catalog/6260/download/9337/DDW_PCA0601_2011_MDDS%20with%20UI.xlsx'
EXPECTED = 'a8eafa7ad73d0d219e3aaee99c49d8cf0773a891b406c67a5b16e59f5ba27aa7'


def prepare():
    source = ROOT / 'pilot/raw/haryana-census/DDW_PCA0601_2011_MDDS.xlsx'
    raw = source.read_bytes()
    assert hashlib.sha256(raw).hexdigest() == EXPECTED
    with zipfile.ZipFile(source) as archive:
        assert archive.testzip() is None
    sheet = openpyxl.load_workbook(source, read_only=True, data_only=True).worksheets[0]
    values = iter(sheet.values)
    headers = list(next(values))
    rows = [dict(zip(headers, row)) for row in values]
    assert all(row['State'] == '06' and row['District'] == '069' for row in rows)
    for row in rows:
        row['source_record_key'] = '|'.join(str(row[key]) for key in headers[:9])
        assert row['TOT_P'] == row['TOT_M'] + row['TOT_F']
    assert len({row['source_record_key'] for row in rows}) == len(rows)
    villages = [row for row in rows if row['Level'] == 'VILLAGE']
    assert len(villages) == 219
    assert sum(row['TOT_P'] for row in villages) == 248063
    extracted = json.dumps({'headers': headers + ['source_record_key'], 'rows': rows,
        'scope': 'Official Panchkula district Census 2011 PCA, all source rows; no map or LGD crosswalk asserted'}, ensure_ascii=False).encode()
    manifest = {'version': 1, 'sources': [{'key': KEY, 'source_url': URL,
        'sha256': EXPECTED, 'extracted_sha256': hashlib.sha256(extracted).hexdigest(),
        'retrieved_at': datetime.fromtimestamp(source.stat().st_mtime, timezone.utc).strftime('%Y-%m-%d %H:%M:%S'), 'options': {'sheet': 'EB-0601'}, 'row_count': len(rows)}]}
    output = ROOT / 'tmp/haryana-punjab-rollout/panchkula-census-package.zip'
    with zipfile.ZipFile(output, 'w', zipfile.ZIP_DEFLATED) as archive:
        archive.writestr(KEY + '.xlsx', raw)
        archive.writestr(KEY + '.json', extracted)
        archive.writestr('manifest.json', json.dumps(manifest))
    assert zipfile.ZipFile(output).testzip() is None
    digest = hashlib.sha256(output.read_bytes()).hexdigest()
    output.with_suffix('.sha256').write_text(digest + '\n')
    print(json.dumps({'package_sha256': digest, 'rows': len(rows), 'villages': len(villages)}))


if __name__ == '__main__':
    prepare()
