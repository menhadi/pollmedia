"""Package source-verified Kerala 2011 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='759495318f66052900b770f9',
    name='pollmedia-ac-2011-kerala-declared-results-20261003',
    prior_sha256='04b5b86f3c0ea73e51a5ec31cff090b9caf0d577a140f2a7034ffaa012452169',
    state='Kerala', seats=140, year=2011,
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
