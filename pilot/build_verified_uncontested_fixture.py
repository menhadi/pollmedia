"""Record source-page evidence for historical seats returned unopposed.

The fixture is deliberately narrower than the list of one-candidate records: a
constituency summary page must name the candidate and explicitly say
"uncontested". It never supplies votes, turnout, or a winning margin.
"""

import csv
import hashlib
import json
import re
from pathlib import Path

import fitz

from audit_pc_ac_zero_values import (
    IMPORTED_PACKAGES,
    PROPOSED_PACKAGES,
    correction_index,
    effective_body,
)

ROOT = Path(__file__).resolve().parents[1]
ARCHIVE = ROOT / "application/storage/app/private/election-archive"
AUDIT = ROOT / "exports/pc-ac-residual-after-20261001-detailed-v2.csv"
OUTPUT = ROOT / "application/database/fixtures/official-uncontested-results.json"
PACKAGES = IMPORTED_PACKAGES + PROPOSED_PACKAGES + (
    "pollmedia-ac-residual-turnout-corrections-20261001-v4.zip",
    "pollmedia-pc-ac-detailed-source-turnout-corrections-20261001-v2.zip",
)


def normal(value):
    return re.sub(r"[^a-z0-9]", "", str(value).casefold())


def build():
    revisions = correction_index(ROOT, PACKAGES)
    audit_rows = list(csv.DictReader(AUDIT.open(encoding="utf-8")))
    by_edition = {}
    for row in audit_rows:
        edition = row["edition"]
        if edition not in by_edition:
            data = json.loads(effective_body(ARCHIVE / edition / "extraction.json", revisions.get(edition, [])))
            manifest = json.loads((ARCHIVE / edition / "manifest.json").read_text(encoding="utf-8"))
            by_edition[edition] = (data, manifest, [])
        data, _, targets = by_edition[edition]
        record = next((r for r in data["records"] if r["code"] == int(row["code"])), None)
        if record is None:
            raise ValueError(f"Missing record: {edition}:{row['code']}")
        candidates = record.get("candidates") or []
        if len(candidates) != 1 or candidates[0].get("votes") not in (None, 0):
            continue
        targets.append((record, candidates[0]))

    verified = {}
    hashed = {}
    for edition, (data, manifest, targets) in by_edition.items():
        if not targets:
            continue
        pdfs = [item for item in manifest["files"] if item["file"].lower().endswith(".pdf") and (
            len(manifest["files"]) <= 4 or any(term in item["name"].casefold() for term in ("summary", "summry", "vol ii"))
        )]
        for item in pdfs:
            pdf = ARCHIVE / edition / item["file"]
            if pdf not in hashed:
                hashed[pdf] = hashlib.sha256(pdf.read_bytes()).hexdigest()
            if hashed[pdf] != item["sha256"]:
                raise ValueError(f"PDF checksum mismatch: {pdf}")
            with fitz.open(pdf) as document:
                for page_number, page in enumerate(document, 1):
                    text = page.get_text()
                    if text.upper().count("CONSTITUENCY DATA - SUMMARY") != 1 or "uncontested" not in text.casefold():
                        continue
                    normalized_page = normal(text)
                    for record, candidate in targets:
                        name = record.get("constituency_name") or record["name"].split("/")[-1].strip()
                        code = record.get("official_pc_code") or record.get("official_ac_code") or record["code"]
                        short_name = re.escape(name.split("(")[0].strip())
                        heading = r"CONSTITUENCY\s*:?\s*" + re.escape(str(code)) + r"\s*[-.]\s*" + short_name
                        older_heading = r"CONSTITUENCY DATA - SUMMARY\s*" + re.escape(str(code)) + r"\s*CONSTITUENCY\s*:\s*-\s*" + short_name
                        if not (re.search(heading, text, re.I) or re.search(older_heading, text, re.I)) or normal(name) not in normalized_page or normal(candidate["candidate_name"]) not in normalized_page:
                            continue
                        key = f"{edition}:{record['code']}"
                        evidence = {
                            "name": name,
                            "candidate": candidate["candidate_name"],
                            "party": candidate.get("party_at_election"),
                            "source_url": data["source_url"],
                            "source_file": item["file"],
                            "source_sha256": item["sha256"],
                            "pdf_page": page_number,
                        }
                        if key in verified and verified[key] != evidence:
                            raise ValueError(f"Conflicting official evidence: {key}")
                        verified[key] = evidence
    return dict(sorted(verified.items()))


if __name__ == "__main__":
    result = build()
    OUTPUT.write_text(json.dumps(result, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Verified {len(result)} uncontested seats in {OUTPUT}")
