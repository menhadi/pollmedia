"""Group four more source-verified 2008 AC result corrections."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave6'
REQUIRED_COMMIT = '58ad027'
BUNDLES = [
    ('pollmedia-ac-2008-chhattisgarh-declared-results-20261003', '0a4e1f7117d693b657dfeb6997b1756b1fece52dcad6f23915247fcbe9cfcbd1'),
    ('pollmedia-ac-2008-jammu-kashmir-declared-results-20261003', 'b99f00ef6ee2a5acacd6738f5bd1d441523e4c8aaca0418ee02a07e00bedb102'),
    ('pollmedia-ac-2008-rajasthan-declared-results-20261003', '78189a9580ea5eb447cd1c56f3264a6c9f7ab4e65efefea83709bdec35f8422e'),
    ('pollmedia-ac-2008-karnataka-declared-results-20261003', '678c6722c7c45af3e35485fa6f60108488872664ca1951b2f098f18582fb176d'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
