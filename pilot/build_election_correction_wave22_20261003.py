"""Ship three source-verified Jharkhand 2014 AC results after wave 21."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave22'
REQUIRED_COMMIT = '3abc823'
BUNDLES = [
    ('pollmedia-ac-2014-jharkhand-remaining-detail-results-20261003',
     'dd231cb8ee7d755fb21cdf3368772b87194e98af9d5d7062a1651e0ded3b2e64'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
