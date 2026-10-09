"""Preserve six official winner declarations with unresolved vote metrics."""
import json
import build_pc_successful_candidate_reviews as shared

NAME = 'pollmedia-pc-1967-1971-successful-candidate-reviews-20261009'
SPECS = [
    ('d7365c7939cfc38b09eb9581', 'pollmedia-pc-1967-summary-missing-votes-20261003', '23a1eeff304d9452b7bd3bac0751eb6a348b0974c0d41661c946dd95806f6a9c', '7ce94cae4bb839f08f745acf83a3155408c1fad26cbdbf826cdcdf154a886c02', [13, 46, 144, 145, 296]),
    ('a62b405d308af2536df91caa', 'pollmedia-pc-1971-summary-results-20261002', '33d4c86bb15f88adaf27c2edb7c5cda15d34010d36f23c79ca2232bd1964bf8e', '4ca12a4231e82169fdb180c9037e1d193e7980b953a4cb58f3f255451e946905', [513]),
]


if __name__ == '__main__':
    print(json.dumps(shared.build(name=NAME, specs=SPECS)))
