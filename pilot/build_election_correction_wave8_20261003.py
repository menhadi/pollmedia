"""Ship four reviewed 2009 Assembly declaration corrections."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave8'
REQUIRED_COMMIT = '64301b0'
BUNDLES = [
    ('pollmedia-ac-2009-haryana-declared-results-20261003', '5b014df4330c15f2082cfc0315a38986d3136d7248814604611ba4b6a689c77a'),
    ('pollmedia-ac-2009-jharkhand-declared-results-20261003', '3c38930557b0d0e329447efd29747ed5e17b139aa10b0a1464c3fe44a92cd14f'),
    ('pollmedia-ac-2009-maharashtra-declared-results-20261003', '12396fa4e1225ac63f77d87d38c2b302ad2e7dcda029663dd2a53826b40ad2e2'),
    ('pollmedia-ac-2009-arunachal-pradesh-declared-results-20261003', 'e5ced51e44c69137c63805186c3737b5a51fc839eab20cc2faa5bd53e1e7aa11'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
