"""Package source-verified Assam 2011 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='6f98f0d1117aa60cd856fc72',
    name='pollmedia-ac-2011-assam-declared-results-20261003',
    prior_sha256='bf4e66d717bfefdc83a1f282ebacf9dcda60193ae01bbd93b0f874daa347a69e',
    state='Assam', seats=126, year=2011,
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
