"""Ship reviewed Himachal Pradesh 2012 result correction."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave13'
REQUIRED_COMMIT = '5b660ae'
BUNDLES = [
    ('pollmedia-ac-2012-himachal-pradesh-declared-results-20261003', 'f3a7f129a1a32e7765ea411d57074809948bceda100fe5f85b8c5e0e92012e91'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
