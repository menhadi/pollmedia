"""Combine independently preserved dropdown sources with the ordinary crawl manifest."""
import json


def load_manifest(path):
    record = json.loads(path.read_text(encoding='utf-8'))
    for supplement in sorted(path.parent.glob('*-supplement.json')):
        extra = json.loads(supplement.read_text(encoding='utf-8'))
        for field, key in [('documents', 'url'), ('api_responses', 'request_id')]:
            items = {item[key]: item for item in record.get(field, [])}
            for item in extra.get(field, []):
                if item.get('file') or item[key] not in items:
                    items[item[key]] = item
            record[field] = list(items.values())
        record['errors'] = record.get('errors', []) + extra.get('errors', [])
    return record
