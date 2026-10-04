"""Guarded 2009 Maldaha Dakshin result after the corrected release chain."""

import build_election_correction_wave_20261003 as release


release.NAME = 'pollmedia-election-corrections-20261004-wave26'
release.REQUIRED_COMMIT = '247bcc8'
release.BUNDLES = [
    ('pollmedia-pc-2009-maldaha-dakshin-summary-result-20261004',
     'f8cfb198a9f5725c5b4dc94439af1c94a8726f815663d7c833491230e0e50e80'),
]


if __name__ == '__main__':
    import json

    print(json.dumps(release.build()))
