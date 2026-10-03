"""Ship the remaining reviewed 2009 Assembly declaration corrections."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave9'
REQUIRED_COMMIT = '32a1267'
BUNDLES = [
    ('pollmedia-ac-2009-sikkim-declared-results-20261003-v2', 'e6b0cc034d6835a40df91126895c1d82dad8ddef96eecfb77a20d7f3cf0c0f28'),
    ('pollmedia-ac-2009-andhra-pradesh-declared-results-20261003', '68cf8e0f07f7b45c2689b2c2e0493ee8d917c12be1f8fb5931ab5fa6f5e6d6c7'),
    ('pollmedia-ac-2009-odisha-declared-results-20261003', '15a3b2fd2a7994a94d2976e3dbdc0c1988a9ed236136c80c8da9bec1fa9393f4'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
