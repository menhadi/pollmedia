"""Package all source-verified Meghalaya 2008 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='d82c217822e367756f2aad6e',
    name='pollmedia-ac-2008-meghalaya-declared-results-20261003',
    prior_sha256='25f7527c2290e4f05ff6332c0a31ee46acead034c78c16ee3cdf5b9b60ffbf0e',
    state='Meghalaya',
    seats=60,
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
