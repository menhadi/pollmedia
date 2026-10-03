"""Package source-verified Manipur 2012 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='bd260e1e2bcf7658789e3a85',
    name='pollmedia-ac-2012-manipur-declared-results-20261003',
    prior_sha256='b7f575a73a2bd6a66b945f3ee3e886182e17a1e4efa424c5e27bdcda28b91239',
    state='Manipur', seats=60, year=2012,
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
