"""Ship complete Jharkhand 2014 AC results after wave 22."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave23'
REQUIRED_COMMIT = 'a52d32d'
BUNDLES = [
    ('pollmedia-ac-2014-jharkhand-duplicate-preview-results-20261003',
     'ae592cbb35669db7d74444c875337a59f86d35e654b7d7ea6fd695a974f3be60'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
