"""Guarded Bihar and Karnataka result corrections following wave 24."""

import build_election_correction_wave_20261003 as release


release.NAME = 'pollmedia-election-corrections-20261004-wave25'
release.REQUIRED_COMMIT = '1125926'
release.BUNDLES = [
    ('pollmedia-ac-bihar-1985-summary-reconciliation-20261003', '5ecd9ef51247f9bdd8cbe25269e881d196a8eb797c8b31767c956e95989c8042'),
    ('pollmedia-ac-karnataka-1983-bagewadi-summary-result-20261004', 'bd29323c5f8c67ac5cc406a42afe9ece84c9ade6c1365bf09e039c80b24cb6b1'),
    ('pollmedia-ac-bihar-1985-hussainabad-declared-result-20261004', '5feee19d89aed9861caf01163e133b2696adc0e14d41a8d558bf842f59c195f4'),
]


if __name__ == '__main__':
    import json

    print(json.dumps(release.build()))
