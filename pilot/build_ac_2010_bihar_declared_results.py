"""Package source-verified Bihar 2010 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='576acbcd20ffc1f7500abcf7',
    name='pollmedia-ac-2010-bihar-declared-results-20261003',
    prior_sha256='436290aefcb21d0ea2ab543c809b8585a4ea716762938f37840afd30e168f377',
    state='Bihar',
    seats=243,
    year=2010,
    prior_packages=('pollmedia-ac-summary-corrections-20261001-v7.zip',),
    expected_revisions=1,
    summary_only_codes=frozenset({204, 205, 206, 207, 208, 212}),
    source_warning_code=None,
    source_note_prefix='Candidate rows transcribed from the detailed PDF;',
    result_warning_code='summary_turnout_with_detail_warnings',
    margin_discrepancies=((172, 23713, 23712),),
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
