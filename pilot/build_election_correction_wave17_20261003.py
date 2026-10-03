"""Ship reviewed 2014 Haryana and Jammu & Kashmir AC declarations."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave17'
REQUIRED_COMMIT = '2d6b416'
BUNDLES = [
    ('pollmedia-ac-2014-jammu-kashmir-small-discrepancy-results-20261003', 'e5c6dfa2fe66f780b49645816fa77e22918168e49cecd4744e8bad83a2e5be15'),
    ('pollmedia-ac-2014-haryana-small-discrepancy-results-20261003', '92aeb9a5e46f5eae80394ace07b9604838aae4b5158308d7ec198a2725d9e0ed'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
