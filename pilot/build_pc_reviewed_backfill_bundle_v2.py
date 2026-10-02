"""Combine the five source-verified PC corrections into one guarded import."""

import json
from pathlib import Path

from build_pc_reviewed_backfill_bundle import CHILDREN, build


NAME = 'pollmedia-pc-reviewed-backfill-20261002-v2'
CHILDREN_V2 = CHILDREN + ('pollmedia-pc-1971-summary-results-20261002',)


if __name__ == '__main__':
    print(json.dumps(build(Path(__file__).resolve().parents[1], NAME, CHILDREN_V2)))
