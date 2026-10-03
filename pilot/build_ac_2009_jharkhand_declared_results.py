"""Package all source-verified Jharkhand 2009 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='0882c0bd6b8d8738e38b6f06',
    name='pollmedia-ac-2009-jharkhand-declared-results-20261003',
    prior_sha256='d26e5932df8a3ccd8777384295edda3504d70963952dac80a10f8bdfc0d6c13f',
    state='Jharkhand',
    seats=81,
    year=2009,
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
