"""Guarded release after the observed-live resume; supersedes wave 24."""

import build_election_correction_wave24_20261004 as previous


release = previous.release
release.NAME = 'pollmedia-election-corrections-20261004-wave24-v2'
release.REQUIRED_COMMIT = 'ee2c43a'
release.BUNDLES = [
    ('pollmedia-ac-west-bengal-1982-champdani-resume-result-20261004',
     '7895452ff069faea023b66eeae78f2561635200a48c0e06362e67bd81d00c3c2')
    if name == 'pollmedia-ac-west-bengal-1982-champdani-declared-result-20261004'
    else (name, checksum)
    for name, checksum in release.BUNDLES
]


if __name__ == '__main__':
    import json

    print(json.dumps(release.build()))
