"""Guarded wave 12 with live-rebased Uttarakhand results."""

import build_election_correction_wave12_20261003 as previous


release = previous.release
NAME = 'pollmedia-election-corrections-20261004-wave12-v2'
REQUIRED_COMMIT = 'db633d8'
BUNDLES = [
    ('pollmedia-ac-2012-uttarakhand-live-rebased-results-20261004',
     'f8e8183979e54882fd115f594368f68efd7a8f8b31fe1d96bfda8e3624a2bbba')
    if name == 'pollmedia-ac-2012-uttarakhand-declared-results-20261003'
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
