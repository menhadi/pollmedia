"""Package source-verified Sikkim 2009 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='172aba6298a6a9113d61177f',
    name='pollmedia-ac-2009-sikkim-declared-results-20261003-v2',
    prior_sha256='b28fbc4a17d775d118e333c92853d065d88d9717d307a77d1af9a0339ffd093e',
    state='Sikkim',
    seats=32,
    year=2009,
    expected_revisions=6,
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
