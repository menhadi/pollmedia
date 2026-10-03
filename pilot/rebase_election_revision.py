"""Apply a verified election revision while preserving unrelated live corrections."""

import json


MISSING = object()
SOURCE_KEYS = ('kind', 'year', 'source_url', 'source_file', 'source_sha256')


def indexed(records: list[dict]) -> dict:
    result = {}
    for record in records:
        code = record.get('code')
        if code is None or code in result:
            raise ValueError('Missing or duplicate constituency code; manual round handling required')
        result[code] = record
    return result


def merge_fields(before: dict, proposed: dict, current: dict, label: str) -> list[str]:
    changed = []
    for key in sorted(set(before) | set(proposed)):
        prior = before.get(key, MISSING)
        target = proposed.get(key, MISSING)
        live = current.get(key, MISSING)
        if prior == target or live == target:
            continue
        if live != prior:
            raise ValueError(f'Overlapping live correction at {label}.{key}')
        if target is MISSING:
            del current[key]
        else:
            current[key] = target
        changed.append(key)
    return changed


def rebase(old_body: bytes, proposed_body: bytes, live_body: bytes) -> tuple[bytes, dict]:
    old, proposed, live = (json.loads(body) for body in (old_body, proposed_body, live_body))
    for key in SOURCE_KEYS:
        if old.get(key, MISSING) != proposed.get(key, MISSING) or old.get(key, MISSING) != live.get(key, MISSING):
            raise ValueError('Election source identity differs: ' + key)
    before = indexed(old['records'])
    intended = indexed(proposed['records'])
    current = indexed(live['records'])
    if set(before) - set(intended) or set(before) - set(current):
        raise ValueError('Constituency deletion or missing live source record')
    changes, added = {}, []
    for code, record in intended.items():
        if code not in before:
            if code in current:
                if current[code] != record:
                    raise ValueError(f'Overlapping added constituency: {code}')
            else:
                live['records'].append(record)
                current[code] = record
                added.append(code)
            continue
        changed = merge_fields(before[code], record, current[code], f'constituency[{code}]')
        if changed:
            changes[str(code)] = changed
    old_top = {key: value for key, value in old.items() if key != 'records'}
    proposed_top = {key: value for key, value in proposed.items() if key != 'records'}
    top_changes = merge_fields(old_top, proposed_top, live, 'edition')
    if len(live['records']) != len(current):
        raise ValueError('Constituency identity count differs after merge')
    details = {'changed_fields': changes, 'added_codes': added, 'edition_fields': top_changes}
    if not changes and not added and not top_changes:
        return live_body, details
    return json.dumps(live, ensure_ascii=False, indent=2).encode('utf-8'), details
