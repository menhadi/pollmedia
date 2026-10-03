"""Package all source-verified Karnataka 2008 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='1be9e8a7976ebdc44658c8e9',
    name='pollmedia-ac-2008-karnataka-declared-results-20261003',
    prior_sha256='210fb7d2d6d9f721812204e81e4dffbeabea087baea06b0a0905cb871eac4ade',
    state='Karnataka',
    seats=224,
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
