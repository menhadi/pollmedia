"""1993 report has 422 constituencies; source codes 233, 279 and 394 are absent."""
import argparse
import json
from extract_state_election_2002 import extract as extract_legacy
from extract_state_election_2007 import save


def extract(path):
    return extract_legacy(path, year=1993)


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('manifest')
    args = parser.parse_args()
    result = save(args.manifest, year=1993, extractor=extract)
    print(json.dumps({key: result[key] for key in ['year', 'validated_count', 'review_count']}))
