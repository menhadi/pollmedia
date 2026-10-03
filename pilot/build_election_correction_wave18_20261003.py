"""Ship the reconciled 2014 Andhra Pradesh Satyavedu result."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave18'
REQUIRED_COMMIT = '542a1b8'
BUNDLES = [
    ('pollmedia-ac-2014-andhra-satyavedu-detail-result-20261003', '0aebbdd6218b04d4d49683d6f2d5b7641937e1239f73613b26c33e8781c86078'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
