"""Ship the verified Jharkhand 2014 AC elector-difference results after wave 19."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave20'
REQUIRED_COMMIT = 'd279d45'
BUNDLES = [
    ('pollmedia-ac-2014-jharkhand-elector-difference-results-20261003',
     '3fb78832e6500fbb8a1acae28786e6cba0fb2d629f5725476f9a91df1c1b947f'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
