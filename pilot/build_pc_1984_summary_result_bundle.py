"""Package the official 1984 Kanakapura PC result hidden by a serial gap."""

import json
from pathlib import Path

from build_pc_1992_summary_result_bundle import build_edition


EDITION = 'cce64acea4705d13e1065e5b'
NAME = 'pollmedia-pc-1984-kanakapura-result-20261002'
TARGET_CODES = {157}
DETAIL_FILE = EDITION + '-9755.pdf'
SUMMARY_FILE = EDITION + '-9756.pdf'


def build(root: Path) -> dict:
    return build_edition(root, EDITION, NAME, TARGET_CODES, DETAIL_FILE, SUMMARY_FILE, 1984)


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1])))
