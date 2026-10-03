"""Package source-verified Punjab 2012 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='c2f44e0b0b38df67af4d4f6d',
    name='pollmedia-ac-2012-punjab-declared-results-20261003',
    prior_sha256='2e6999737ace0742907242def2ae50ac56de9057bab843715ccd50a955cd92ab',
    state='Punjab', seats=117, year=2012,
    prior_packages=('pollmedia-ac-summary-corrections-20261001-v7.zip',),
    expected_revisions=1, source_warning_code=None,
    source_note_prefix='Candidate rows transcribed from the detailed PDF;',
    result_warning_code='summary_turnout_with_detail_warnings',
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
