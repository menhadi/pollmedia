"""Package source-verified Odisha 2009 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='b56245f92253914a7569bae3',
    name='pollmedia-ac-2009-odisha-declared-results-20261003',
    prior_sha256='4096ec1566ba5eb43bc14d117a17c5b365041d51d26604ffd4e8ff3f01370604',
    state='Odisha',
    pdf_state='Orissa',
    seats=147,
    year=2009,
    prior_packages=('pollmedia-ac-summary-corrections-20261001-v7.zip',),
    expected_revisions=1,
    summary_only_codes=frozenset({23, 81, 97, 98, 99, 109, 110, 114, 118, 137, 138, 139, 140, 141, 143, 144, 145}),
    source_warning_code=None,
    source_note_prefix='Candidate rows transcribed from the detailed PDF;',
    result_warning_code='summary_turnout_with_detail_warnings',
    margin_discrepancies=((89, 25590, 25510),),
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
