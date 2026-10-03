"""Package contested Arunachal Pradesh 2009 AC declarations; retain unopposed rows."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='1901084c7189cfe9433f1842',
    name='pollmedia-ac-2009-arunachal-pradesh-declared-results-20261003',
    prior_sha256='fc29213c6658c11b403b18a083901d9d599d0a2e42c8a6c0c901d41f1a293d5d',
    state='Arunachal Pradesh',
    seats=60,
    year=2009,
    uncontested_codes=frozenset({1, 2, 3}),
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
