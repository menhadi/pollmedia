"""Package source-verified Himachal Pradesh 2012 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, SOURCE_NOTE, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='13651fccf222dbaabb514501',
    name='pollmedia-ac-2012-himachal-pradesh-declared-results-20261003',
    prior_sha256='2b5c9ddd0b41e1df6e25255571d90a34121acc8c12a9254fe72e9ec42a6a118b',
    state='Himachal Pradesh', seats=68, year=2012,
    prior_packages=('pollmedia-ac-summary-corrections-20261001-v7.zip',),
    expected_revisions=1,
    summary_only_codes=frozenset({2, 15, 39, 59, 63}),
    source_warning_code=None,
    source_note_prefix='Candidate rows transcribed from the detailed PDF;',
    result_warning_code='summary_turnout_with_detail_warnings',
    warning_overrides=(
        (12, 'summary_turnout_with_detail_warnings', SOURCE_NOTE),
        (35, 'summary_turnout_with_detail_warnings', SOURCE_NOTE),
        (51, 'summary_elector_difference', 'Detailed result lists 74,261 electors;'),
        (60, 'summary_turnout_with_detail_warnings', SOURCE_NOTE),
        (64, 'summary_turnout_with_detail_warnings', SOURCE_NOTE),
    ),
    elector_differences=((51, 74261, 74262),),
    unreconciled_summary_rows=(
        (36, 45915, 45912, 46128),
        (37, 44847, 44844, 45069),
        (43, 54730, 54726, 54796),
        (57, 44324, 44323, 44432),
        (61, 55386, 55385, 55518),
        (62, 36165, 36164, 36251),
    ),
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
