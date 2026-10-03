"""Package all source-verified Nagaland 2008 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='044bf7c98b9f57f1edb7ab5b',
    name='pollmedia-ac-2008-nagaland-declared-results-20261003',
    prior_sha256='2b341d5648a64a455fa5fda256513b911eb2e845405c408a7ee3ab9e239af848',
    state='Nagaland',
    seats=60,
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
