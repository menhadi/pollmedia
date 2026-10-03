"""Ship source-reviewed West Bengal 2011 results with discrepancy notes."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave11'
REQUIRED_COMMIT = 'b608a7e'
BUNDLES = [
    ('pollmedia-ac-2011-west-bengal-declared-results-20261003', '3e4ed6b31c799830a567ada4fa16f870f86bb1d191b147cfe1ea7667dc4f05a3'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
