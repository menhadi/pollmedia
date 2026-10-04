"""Only the remaining Bihar 1985 result; Bagewadi is already in the resume."""

import build_election_correction_wave25_20261004 as previous


release = previous.release
release.NAME = 'pollmedia-election-corrections-20261004-wave25-v2'
release.REQUIRED_COMMIT = 'ee2c43a'
release.BUNDLES = [(name, checksum) for name, checksum in release.BUNDLES
                   if name != 'pollmedia-ac-karnataka-1983-bagewadi-summary-result-20261004']


if __name__ == '__main__':
    import json

    print(json.dumps(release.build()))
