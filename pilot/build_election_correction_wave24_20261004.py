"""One guarded upload for the 26 verified election corrections after wave 23."""

import build_election_correction_wave_20261003 as release


release.NAME = 'pollmedia-election-corrections-20261004-wave24'
release.REQUIRED_COMMIT = 'f519207'
release.BUNDLES = [
    ('pollmedia-ac-arunachal-2014-summary-results-20261003', '070cdd883cd77608a6259eee5750a7567df199e48bddb7e8f117e7837dcf4c17'),
    ('pollmedia-ac-maharashtra-2014-declared-results-20261003', 'b352f6c3790e829968bd186580e4f32eae415d11511d0b4c4d1c3087a06719f4'),
    ('pollmedia-ac-gujarat-2012-summary-results-20261003-v5', 'ccd5f2846053fa71fb25e8ba2168dd14e7de0e2dcf305a2a1651fc1e6573189b'),
    ('pollmedia-ac-chhattisgarh-2018-detail-results-20261003', '9ffe0345e635bd72cd95089c4e1fde3ee8dfb531d179022f38571c9bb6979b08'),
    ('pollmedia-ac-bihar-2005-two-round-summaries-20261003-v2', 'a6a9e81a8a09468161b374ec88526aa252744fd284528b1e9516948034298f52'),
    ('pollmedia-ac-kerala-1960-official-summaries-20261004', 'dbe8c840c6c46d8f265f3be2b2cebc9f63209dbd50cbd4ab792217e4ce56b65f'),
    ('pollmedia-ac-arunachal-2004-official-results-20261004', 'da3ec117902b48ed56751bdd573185dfa30ab56bbf5a5d6e88af4c6fce2dee07'),
    ('pollmedia-ac-1957-hata-declared-result-20261004', 'fff467713b2c648d6785c5c9bff9aabc226461111aade64c962f2b4acf3130f2'),
    ('pollmedia-ac-1957-sausar-declared-result-20261004', '4086b097ef5ef8cd49b3d3605c2ef64a0dff06dd4d02797fad9b073cccca738f'),
    ('pollmedia-ac-bombay-1951-four-single-seat-results-20261004', '692e153ac25ce7e67ebc2af5c622421fe5ae8ba0966027bbd9d83aab54025ea6'),
    ('pollmedia-ac-1951-sourastra-mysore-four-summary-results-20261004', 'd21b987dfb68eeadf6752a57397f79be25c14d4f8b06623d5210e5dbf76b3711'),
    ('pollmedia-ac-up-1951-28-truncated-summary-results-20261004', '28f3ccfac1fa287fd79c2631476d64c7212cf0a0f85656ec1b7b88fe6695c275'),
    ('pollmedia-ac-up-1951-three-results-four-two-seat-20261004', '8e2945a5c990dd0d1b4b24855bbdc2a9c717d45b81baaa96a37be54414a52996'),
    ('pollmedia-ac-up-1951-kanpur-invalid-turnout-result-20261004', '42eff73835fd4cf3d46ae2ec67d6cce5d3b7d5017da21324f214d88e0814798a'),
    ('pollmedia-ac-andhra-1955-sattenpalli-invalid-turnout-result-20261004', 'd71626898e457532ad2383ca177f02d0f78ceeeea3e321691a54967153e72c89'),
    ('pollmedia-ac-maharashtra-1962-mahad-declared-tie-20261004', '11c24d6a8981279d235f1222e196c6b1e0e33bc9440bef87e6f09e78cccd001b'),
    ('pollmedia-ac-west-bengal-1971-deganga-summary-result-20261004', '4020d6e9c69eb31e65ac9c7b5ced3ff7e5e6434ac4d3344039e7965dc743bc77'),
    ('pollmedia-ac-tamil-nadu-1971-two-invalid-turnout-results-20261004', '1e94ebb7a35281ce2f27be8f058afdb058fef4171350e4900affad1b1ad34a7c'),
    ('pollmedia-ac-andhra-1978-attili-detail-result-20261004', 'ef8b919dbdcba7092e5d0d6252cd2779bad60725d4b70854f8bd8a6ac50a3565'),
    ('pollmedia-ac-west-bengal-1982-champdani-declared-result-20261004', '7c0e1951fb90699acbb32e79bae134ca035be2eeaf3f3a3473fd8d0c52dd2d0e'),
    ('pollmedia-ac-meghalaya-1988-kherapara-declared-tie-20261004', 'd0799595cf64eddbe83b3387c25db1453c61ba9a3c9845e3b3e13b82e957773b'),
    ('pollmedia-ac-haryana-1991-six-summary-results-20261004', '4bdd02aa05c96f6f19ff2af16792a3cb4c87a6caf5295b0d3ae2978cbe33f976'),
    ('pollmedia-ac-1991-three-summary-results-20261004', '5cb47c375175c81dd7c939d0609910388dfa2ff75da2d53abdd387dc048d4d77'),
    ('pollmedia-ac-tamil-nadu-1996-modakurichi-summary-result-20261004', '10600d2c67b9bfaa52472a31028cdda641d49e7410427599f51b2a9b193aad5a'),
    ('pollmedia-ac-west-bengal-1996-four-summary-results-20261004', '1a2aed7b907a30610fd0c0faa9fbd2b02bb9678d7bfa0ef3040086596d27fe56'),
    ('pollmedia-ac-jammu-kashmir-1996-postal-summary-results-20261004', '567d19bb92152cff2b95816f21f4a548273320cb15e6345794ecb5e4c2c1c22c'),
]


if __name__ == '__main__':
    import json

    print(json.dumps(release.build()))
