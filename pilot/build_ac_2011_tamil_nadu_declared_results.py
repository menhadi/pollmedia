"""Package source-verified Tamil Nadu 2011 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='23cc38bf6d2d08576d05c3ff',
    name='pollmedia-ac-2011-tamil-nadu-declared-results-20261003',
    prior_sha256='29ddfe94b2a89f7f672562030abecca0ea120be9bc539d9f908eec9fb059c416',
    state='Tamil Nadu', seats=234, year=2011,
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
