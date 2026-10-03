"""Ship four reviewed 2012 Assembly election corrections."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave12'
REQUIRED_COMMIT = '5d1415f'
BUNDLES = [
    ('pollmedia-ac-2012-goa-declared-results-20261003', '75f2234cc77b066d0243ddadd31fcd418627e56b95a88d01c0dd3e18b987ac8f'),
    ('pollmedia-ac-2012-manipur-declared-results-20261003', '00eebc3eff08be36361540f64879e02e9aa55e2a08a94bdf8eb5399869c27a60'),
    ('pollmedia-ac-2012-punjab-declared-results-20261003', '06c9be76257695b0c57c7dcf9410ab81d75cb4bf5011610e15e9574750c01996'),
    ('pollmedia-ac-2012-uttarakhand-declared-results-20261003', '980f1087cdb5da845f7e4d971085bb7d10b2d6a83586d97b4cb6bb9f7ae3a9b7'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
