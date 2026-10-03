"""Package all source-verified Jammu & Kashmir 2008 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='25c81eb8ee370d8948a1dc3c',
    name='pollmedia-ac-2008-jammu-kashmir-declared-results-20261003',
    prior_sha256='b539f5f0f06597b3e56ce46f7530eac7c487b53bc4ae2e55a9238afec946ba38',
    state='Jammu & Kashmir',
    seats=87,
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
