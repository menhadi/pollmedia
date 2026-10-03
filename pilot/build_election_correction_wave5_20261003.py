"""Group four source-verified 2008 AC result corrections."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave5'
REQUIRED_COMMIT = '3895342'
BUNDLES = [
    ('pollmedia-ac-2008-mizoram-declared-results-20261003', '8e68ab26f5cfb4327b8936dbccf378adad02443a7cdc2e974d47f72a03df4cb6'),
    ('pollmedia-ac-2008-tripura-declared-results-20261003', 'fb2504b339dea1fef0a4f73cd1fa9717255f0fdc1532144eb4bc59a6d551f343'),
    ('pollmedia-ac-2008-meghalaya-declared-results-20261003', 'd7a46511e0f30675e1c1b415a37434af158e567b54bd6989daeedb2d371899f4'),
    ('pollmedia-ac-2008-nagaland-declared-results-20261003', '50e938b9c9cb85858556a63e2ccbdb4f757b3478a69fa69e8615466cd3e150d0'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
