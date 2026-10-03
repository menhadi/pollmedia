"""Ship the source-backed Karnataka 2013 Aland declaration."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave16'
REQUIRED_COMMIT = '10a88c2'
BUNDLES = [
    ('pollmedia-ac-2013-karnataka-aland-declared-result-20261003', '7659f804f3de7384dfe96794b485ddb7b388dd4f4174a11f58752866e49d9dec'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
