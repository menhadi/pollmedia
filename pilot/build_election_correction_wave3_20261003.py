"""Group the guarded Raiganj and Gujarat result corrections."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave3'
REQUIRED_COMMIT = '6122a7a'
BUNDLES = [
    ('pollmedia-ac-2006-wb-raiganj-margin-review-20261003', '52e81e49a4b0b5ca36c9208f5d7612a78a122aecb573233c7e622253733dc11c'),
    ('pollmedia-ac-2007-gujarat-declared-results-20261003', '1efb40623a388159776e16baf2d832a04d16e5e759c4251a2405dcf1084ca45d'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
