"""Package 1991 PC results verified against the official constituency summaries."""

import json
from pathlib import Path

from build_pc_1992_summary_result_bundle import build_edition


EDITION = '20a9b690ba551149c638cd4f'
NAME = 'pollmedia-pc-1991-summary-results-20261002'
TARGET_CODES = {292, 314, 325, 351, 354, 356, 367, 396, 400, 403, 412, 418,
                451, 458, 462, 463, 471, 472, 473, 482, 486, 509}
DETAIL_FILE = EDITION + '-9764.pdf'
SUMMARY_FILE = EDITION + '-9765.pdf'


def build(root: Path) -> dict:
    return build_edition(root, EDITION, NAME, TARGET_CODES, DETAIL_FILE, SUMMARY_FILE, 1991)


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
