"""Package source-verified Uttarakhand 2012 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='ad5a2b4658b6047e9d0cd86b',
    name='pollmedia-ac-2012-uttarakhand-declared-results-20261003',
    prior_sha256='f4d7ec63dfc8a6c87e3aa72ffc44848248e94b94005422e673ab50359b14979e',
    state='Uttarakhand', seats=70, year=2012,
    prior_packages=('pollmedia-ac-summary-corrections-20261001-v7.zip',),
    expected_revisions=1, source_warning_code=None,
    source_note_prefix='Candidate rows transcribed from the detailed PDF;',
    result_warning_code='summary_turnout_with_detail_warnings',
    unreconciled_summary_rows=(
        (4, 54994, 54973, 55225),
        (6, 48941, 48937, 49411),
        (67, 72865, 72863, 72960),
    ),
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
