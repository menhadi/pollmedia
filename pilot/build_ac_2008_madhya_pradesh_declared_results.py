"""Package official Madhya Pradesh 2008 declarations from the v7 correction state."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='c9e2b9c993bfe7d06547c8f2',
    name='pollmedia-ac-2008-madhya-pradesh-declared-results-20261003',
    prior_sha256='66916969dbd0b4c5025e8a63213b3beb486303422f85aec0b1170990e073acf4',
    state='Madhya Pradesh',
    seats=230,
    # v6 and v7 are alternative revisions of the same base; v7 is the imported edition.
    prior_packages=('pollmedia-ac-summary-corrections-20261001-v7.zip',),
    expected_revisions=1,
    summary_only_codes=frozenset({20, 180}),
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
