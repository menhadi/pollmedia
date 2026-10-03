"""Ship reviewed 2010 and 2011 Assembly declarations."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave10'
REQUIRED_COMMIT = '97e68e6'
BUNDLES = [
    ('pollmedia-ac-2010-bihar-declared-results-20261003', 'd6be790488c2939b735664528d2b840d194228cc7780e179505f0462a4115c93'),
    ('pollmedia-ac-2011-assam-declared-results-20261003', 'b09074cfc342f57650c533371bf64cbdfe9880867a98b2ef971aed6e1e496fcd'),
    ('pollmedia-ac-2011-kerala-declared-results-20261003', '243f8b379f780c0336abf95c13a31ebf235661824bb3963c514b98666ea9a0e2'),
    ('pollmedia-ac-2011-puducherry-declared-results-20261003', '32203d0eeac40abb28506101f0c10b5e3abdc89ec3cbc72bcf2aa3954930d76d'),
    ('pollmedia-ac-2011-tamil-nadu-declared-results-20261003', '847f6b019b18bb82929f60cd6729639e13cc0b81eac168ee166077af32499170'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
