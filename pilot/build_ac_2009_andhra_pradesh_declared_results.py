"""Package source-verified Andhra Pradesh 2009 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='163db19f7d25b7f62c9aa360',
    name='pollmedia-ac-2009-andhra-pradesh-declared-results-20261003',
    prior_sha256='16b7c5063ab0e0e57c5660fdaf54d314464bf7b109e81ade6436911306b5ddfa',
    state='Andhra Pradesh',
    seats=294,
    year=2009,
    prior_packages=('pollmedia-ac-summary-corrections-20261001-v7.zip',),
    expected_revisions=1,
    summary_only_codes=frozenset({10, 118, 121, 146, 154, 155, 156, 157, 160, 186, 188, 189, 255, 256, 271, 272, 274}),
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
