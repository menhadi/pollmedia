"""Package all source-verified Chhattisgarh 2008 AC declarations."""

import json

from build_ac_2008_mizoram_declared_results import SourceConfig, build as build_official, revised_edition as revise_official


CONFIG = SourceConfig(
    edition='0879916bcfd2b319f6728f33',
    name='pollmedia-ac-2008-chhattisgarh-declared-results-20261003',
    prior_sha256='2597109f7685cc2ac11d1638dcd6633de59114229a54991c29caa7df13a2344a',
    state='Chhattisgarh',
    seats=90,
    pdf_state='Chattisgarh',  # Exact spelling printed in this official PDF.
)


def revised_edition():
    return revise_official(config=CONFIG)


def build():
    return build_official(config=CONFIG)


if __name__ == '__main__':
    print(json.dumps(build()))
