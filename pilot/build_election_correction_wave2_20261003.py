"""Consolidate four verified 2006 AC corrections after the first release."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave2'
REQUIRED_COMMIT = 'b7023ac'
BUNDLES = [
    ('pollmedia-ac-2006-tn-declared-results-20261003', '361fae8d69ab0a5c374aae24bb2cfe81df3675e2343b1d224812677148e6b063'),
    ('pollmedia-ac-2006-assam-declared-results-20261003', 'd3a79707753cad4dd59992a30c59b92b47f5e57b6c0bf720d383311a23a95feb'),
    ('pollmedia-ac-2006-puducherry-declared-results-20261003', 'aa494cc785262326706ae05869b3a8557b07629980cab04aae4182d583d4b6a0'),
    ('pollmedia-ac-2006-kerala-declared-results-20261003', '1e4fa7a96526fee7b40a80fcedef46e7c925e5f36c62d3a3f8c22200e58db412'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
