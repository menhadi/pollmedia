"""Ship source-backed 2013 MP and Rajasthan AC declarations."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave15'
REQUIRED_COMMIT = '195841b'
BUNDLES = [
    ('pollmedia-ac-2013-madhya-pradesh-small-discrepancy-results-20261003', '4ee45060e793754d298c88080602fe0d21f5feded6757ee0d20945cb7d927dc5'),
    ('pollmedia-ac-2013-rajasthan-small-discrepancy-results-20261003', 'a8759c39e25b37e352ed805f5463d4adef50549957b909f706b9052d1f2718aa'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
