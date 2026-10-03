"""Ship seven reviewed Uttar Pradesh 2012 declarations."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave14'
REQUIRED_COMMIT = 'ce10beb'
BUNDLES = [
    ('pollmedia-ac-2012-up-seven-declared-results-20261003', '9fd2c25da2ca0aa6eea39e5c4d2ab91e33243d0a488f4d554af5a3a403a4b1c7'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
