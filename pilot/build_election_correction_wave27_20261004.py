"""Guarded 2014 PC declarations for four repeated-name source tables."""

import build_election_correction_wave_20261003 as release


release.NAME = 'pollmedia-election-corrections-20261004-wave27'
release.REQUIRED_COMMIT = '9dfacf1'
release.BUNDLES = [
    ('pollmedia-pc-2014-repeated-names-official-results-20261003',
     'f0f608fc6cbd8c6154696ad631c63c04c2d5fc5d2348d18ca5be55f118a551e4'),
]


if __name__ == '__main__':
    import json

    print(json.dumps(release.build()))
