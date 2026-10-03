"""Package source-verified West Bengal 2011 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='79ebd83ef86bed336cc3ef0f',
    name='pollmedia-ac-2011-west-bengal-declared-results-20261003',
    prior_sha256='6df3220522335c31e6abb9a7b23626a1bcd05a5765451050a6388d5ad705cfc8',
    state='West Bengal', seats=294, year=2011,
    prior_packages=('pollmedia-ac-summary-corrections-20261001-v7.zip',),
    expected_revisions=1, source_warning_code=None,
    source_note_prefix='Candidate rows transcribed from the detailed PDF;',
    result_warning_code='summary_turnout_with_detail_warnings',
    unreconciled_summary_rows=(
        (11, 154569, 154565, 154691),
        (98, 170899, 170769, 170776),
        (206, 178685, 178682, 178684),
        (211, 173240, 173239, 173421),
        (215, 165369, 165366, 165599),
    ),
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
