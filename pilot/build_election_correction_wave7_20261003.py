"""Ship the guarded Madhya Pradesh 2008 v7-based result correction."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave7'
REQUIRED_COMMIT = 'de340cf'
BUNDLES = [
    ('pollmedia-ac-2008-madhya-pradesh-declared-results-20261003', 'e34fe736a10b2fc9c0dd6aa9d9eb37b7e9272da1f114fa1c2cf874634bfcb68e'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
