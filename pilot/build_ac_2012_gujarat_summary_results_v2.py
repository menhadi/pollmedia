"""Build the refined Gujarat 2012 bundle directly from the verified prior bytes."""

import json
from build_ac_2012_gujarat_summary_results import build


if __name__ == '__main__':
    print(json.dumps(build(refined=True)))
