"""Ship the verified Jharkhand 2014 AC summary declarations."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave19'
REQUIRED_COMMIT = '6ea6e2d'
BUNDLES = [
    ('pollmedia-ac-2014-jharkhand-summary-only-results-20261003',
     '4a502b9f427f83990aa1a208cd99a2bf2c7a62a91bf30439bf4a10cfb329f9e1'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
