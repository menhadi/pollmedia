"""Package official Maharashtra 2009 declarations from the v7 correction state."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='cc0185e917711e78c149abf4',
    name='pollmedia-ac-2009-maharashtra-declared-results-20261003',
    prior_sha256='05976ff9b97038b2bc8163aad90e59a24242cde63566b3dd696f8f0b83906c59',
    state='Maharashtra',
    seats=288,
    year=2009,
    # v6 and v7 replace the same base; the v7 extraction is the tested predecessor.
    prior_packages=('pollmedia-ac-summary-corrections-20261001-v7.zip',),
    expected_revisions=1,
    summary_only_codes=frozenset({142, 233, 287}),
    margin_discrepancies=((134, 2291, 2192), (178, 9710, 9709)),
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
