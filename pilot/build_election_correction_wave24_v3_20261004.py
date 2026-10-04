"""Guarded wave 24 with live-rebased Gujarat results."""

import build_election_correction_wave24_v2_20261004 as previous


release = previous.release
NAME = 'pollmedia-election-corrections-20261004-wave24-v3'
REQUIRED_COMMIT = 'db633d8'
BUNDLES = [
    ('pollmedia-ac-2012-gujarat-live-rebased-results-20261004',
     'b9eda87cf5ade2c2e9a54048179c4f98ae9fe19e1b6abf02ce8fcf204f4422d5')
    if name == 'pollmedia-ac-gujarat-2012-summary-results-20261003-v5'
    else (name, checksum)
    for name, checksum in release.BUNDLES
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
