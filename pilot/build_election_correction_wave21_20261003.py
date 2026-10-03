"""Ship two source-verified Jharkhand 2014 AC detailed results after wave 20."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave21'
REQUIRED_COMMIT = '64cc1c7'
BUNDLES = [
    ('pollmedia-ac-2014-jharkhand-single-page-detail-results-20261003',
     '497240e1e7ccf89af67054e50c4b846c422f94d5bf48c9a7b58963d06ae80935'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
