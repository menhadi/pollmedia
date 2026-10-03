"""Build Gujarat 2012 summary results with checked first-pass numeric fallback."""

import json
from build_ac_2012_gujarat_summary_results import build


if __name__ == '__main__':
    print(json.dumps(build(refined=True, shifted_pages=True, result_fallback=True)))
