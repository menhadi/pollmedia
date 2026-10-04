"""Guarded West Bengal 2011 results rebased on the observed live turnout revision."""

import build_election_correction_wave_20261003 as release


release.NAME = 'pollmedia-election-corrections-20261004-wave11-v2'
release.REQUIRED_COMMIT = '526e30e'
release.BUNDLES = [
    ('pollmedia-ac-2011-wb-live-rebased-results-20261004',
     '70b36e9ca215fdca9b6fa7310934a766bf66d9b54f9b7cb0410f99b030249c8a'),
]


if __name__ == '__main__':
    import json

    print(json.dumps(release.build()))
