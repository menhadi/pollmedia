"""Package source-verified Puducherry 2011 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='d964b3bce62e657ee36db2cb',
    name='pollmedia-ac-2011-puducherry-declared-results-20261003',
    prior_sha256='941c966cbbd979dfe02dc6a9089cd3e716a6c0253796e8c1643f88dc7a6a56c4',
    state='Puducherry',
    seats=30,
    year=2011,
    prior_packages=('pollmedia-ac-summary-corrections-20261001-v7.zip',),
    expected_revisions=1,
    source_warning_code=None,
    source_note_prefix='Candidate rows transcribed from the detailed PDF;',
    result_warning_code='summary_turnout_with_detail_warnings',
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
