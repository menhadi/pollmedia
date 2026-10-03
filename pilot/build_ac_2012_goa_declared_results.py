"""Package source-verified Goa 2012 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='9848e8b2829fecdec25effa5',
    name='pollmedia-ac-2012-goa-declared-results-20261003',
    prior_sha256='205d9baefb80c37db33b57386d8eca7f3d414072ccc982267ae121db8252c385',
    state='Goa', seats=40, year=2012,
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
