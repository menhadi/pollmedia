"""Extract dated UP constituency relationships from archived official PDF tables."""
import hashlib
import json
import re
import sys
from pathlib import Path
import fitz


def clean(value):
    return re.sub(r"\s+", " ", value or "").strip()


def extract(root):
    pcs = []
    district_rows = []
    delimitation = root / "up-delimitation.pdf"
    gazette = root / "up-district-gazette-2023.pdf"
    with fitz.open(delimitation) as doc:
        for index in range(41, 46):
            for table in doc[index].find_tables().tables:
                for row in table.extract():
                    if len(row) != 2:
                        continue
                    match = re.fullmatch(r"(\d+)\s*-\s*(.+)", clean(row[0]))
                    if not match:
                        continue
                    members = [int(x) for x in re.findall(r"(?:^|[, &])\s*(\d+)\s*(?:-|(?=[A-Za-z]))", clean(row[1]))]
                    pcs.append({"code": int(match[1]), "name": re.sub(r"\s*\(SC\)$", "", match[2]).strip(), "ac_codes": members, "page": index + 1, "source_text": clean(row[1])})
    district = None
    with fitz.open(gazette) as doc:
        for index in range(11, 22):
            for table in doc[index].find_tables().tables:
                for row in table.extract():
                    if len(row) != 3:
                        continue
                    match = re.fullmatch(r"(\d+)\s*-\s*(.+)", clean(row[1]))
                    if not match:
                        continue
                    district = clean(row[0]) or district
                    if not district:
                        raise ValueError("Unresolved continued district cell")
                    district_rows.append({"code": int(match[1]), "name": match[2], "district": district, "page": index + 1})
    assert [r["code"] for r in pcs] == list(range(1, 81)), "Incomplete PC list"
    assert sorted(c for r in pcs for c in r["ac_codes"]) == list(range(1, 404)), "Incomplete or duplicate PC membership"
    assert sorted(r["code"] for r in district_rows) == list(range(1, 404)), "Incomplete or duplicate district membership"
    assert len(set(r["district"] for r in district_rows)) == 75, "District count differs"
    return {"pc_sha256": hashlib.sha256(delimitation.read_bytes()).hexdigest(), "district_sha256": hashlib.sha256(gazette.read_bytes()).hexdigest(), "pc_source_date": "2006-12-18", "district_source_date": "2023-10-06", "pcs": pcs, "district_rows": district_rows}


if __name__ == "__main__":
    result = extract(Path(sys.argv[1]))
    Path(sys.argv[2]).write_text(json.dumps(result, indent=2, ensure_ascii=False) + "\n", encoding="utf-8")
    print(f"Extracted {len(result['pcs'])} PCs and {len(result['district_rows'])} district assignments")
