"""Package all source-verified Tripura 2008 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='c2b9ef2bc73bbcc70a271a58',
    name='pollmedia-ac-2008-tripura-declared-results-20261003',
    prior_sha256='a895e344ed0e32ef15999514cf45333b00ee1a40cc8b500c1ba85de421db2448',
    state='Tripura',
    seats=60,
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
