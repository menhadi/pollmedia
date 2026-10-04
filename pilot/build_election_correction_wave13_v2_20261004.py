"""Guarded wave 13 with live-rebased Himachal Pradesh results."""

import build_election_correction_wave13_20261003 as previous


release = previous.release
NAME = 'pollmedia-election-corrections-20261004-wave13-v2'
REQUIRED_COMMIT = 'db633d8'
BUNDLES = [
    ('pollmedia-ac-2012-himachal-live-rebased-results-20261004',
     '563c2cdc982a07cb66dd3b7fb46a282c70c232950f8020d8ae1322ac3ae844cf')
    if name == 'pollmedia-ac-2012-himachal-pradesh-declared-results-20261003'
    else (name, checksum)
    for name, checksum in previous.BUNDLES
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
