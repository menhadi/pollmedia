"""Combine independently preserved dropdown sources with the ordinary crawl manifest."""
import json
import time


def replace_checkpoint(temporary, destination):
    """Keep atomic replacement while allowing brief Windows reader/antivirus locks."""
    for attempt in range(8):
        try:
            temporary.replace(destination)
            return
        except PermissionError:
            if attempt == 7:
                raise
            time.sleep(0.1 * (attempt + 1))


def load_manifest(path):
    record = json.loads(path.read_text(encoding='utf-8'))
    for supplement in sorted(path.parent.glob('*-supplement.json')):
        extra = json.loads(supplement.read_text(encoding='utf-8'))
        for field, key in [('documents', 'url'), ('api_responses', 'request_id')]:
            identity = lambda item: item.get('request_id', item.get(key))
            items = {identity(item): item for item in record.get(field, [])}
            for item in extra.get(field, []):
                if item.get('file') or identity(item) not in items:
                    items[identity(item)] = item
            record[field] = list(items.values())
        record['errors'] = record.get('errors', []) + extra.get('errors', [])
    return record
