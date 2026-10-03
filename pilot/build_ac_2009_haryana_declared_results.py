"""Package all source-verified Haryana 2009 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='4ac73455f798dcf3a8d2946f',
    name='pollmedia-ac-2009-haryana-declared-results-20261003',
    prior_sha256='f94632902d88f0bd35ea9f4d718659520283cd01c83aca12f565fe559b7ac55a',
    state='Haryana',
    seats=90,
    year=2009,
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
