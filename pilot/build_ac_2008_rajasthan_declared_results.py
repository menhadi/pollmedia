"""Package all source-verified Rajasthan 2008 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='f6de544acb0c3ab5a0c23542',
    name='pollmedia-ac-2008-rajasthan-declared-results-20261003',
    prior_sha256='47735f0f503be1f2d851b34d778f995d59fd355b4d4ef602e98dbdf44a5ed106',
    state='Rajasthan',
    seats=200,
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
