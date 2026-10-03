"""Group four source-verified 2007 AC result corrections."""

import build_election_correction_wave_20261003 as release


NAME = 'pollmedia-election-corrections-20261003-wave4'
REQUIRED_COMMIT = '8f6b58e'
BUNDLES = [
    ('pollmedia-ac-2007-himachal-pradesh-declared-results-20261003', '4501948fa1c9ec516a0bf58495863f967abba124e2dd5de2f9c50ada20e30642'),
    ('pollmedia-ac-2007-punjab-declared-results-20261003', 'a64f878884e7ca4de7aa8572897699113780adda804323b6349a29d951fb0f66'),
    ('pollmedia-ac-2007-uttarakhand-declared-results-20261003', 'bef9b3dee716bb7a983cef5d75a1705de228574c016288ee091891f7219181ac'),
    ('pollmedia-ac-2007-uttar-pradesh-declared-results-20261003', 'f759560ac2f7423a6e54ec019cf02c04cf152e6d9aab49f943488515afa35cfb'),
]


def build():
    release.NAME = NAME
    release.REQUIRED_COMMIT = REQUIRED_COMMIT
    release.BUNDLES = BUNDLES
    return release.build()


if __name__ == '__main__':
    import json

    print(json.dumps(build()))
