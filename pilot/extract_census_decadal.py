"""Extract official A-02 with original cells and all source footnotes intact."""
import hashlib
import json
from pathlib import Path
import re
import sys

sys.path.insert(0, str(Path(__file__).parent / "tmp" / "python-libs"))
import xlrd


def extract(path, manifest):
    raw = Path(path).read_bytes()
    if hashlib.sha256(raw).hexdigest() != manifest["sha256"]:
        raise ValueError("Source checksum mismatch")
    sheet = xlrd.open_workbook(file_contents=raw).sheet_by_name("A-2")
    if sheet.ncols != 9 or sheet.cell_value(1, 4) != "Persons":
        raise ValueError("A-02 headers changed")
    records, notes, identity = [], [], None
    for index in range(6, sheet.nrows):
        cells = sheet.row_values(index)
        year_text = str(cells[3]).strip()
        year_match = re.match(r"^(19[0-9][0-9]|2001|2011)(?:\.0)?(.*)$", year_text)
        if year_match:
            if str(cells[0]).strip():
                identity = [str(cells[0]).strip(), str(cells[1]).strip(), str(cells[2]).strip()]
            if identity is None:
                raise ValueError("Missing geography")
            year = int(year_match[1])
            flags = []
            if year not in range(1901, 2012, 10):
                flags.append("Year is recorded as " + str(year) + " in the source; it has not been shifted to the standard Census year.")
            marker = year_match[2].strip()
            if marker:
                flags.append("Source year marker " + marker + ": see original footnotes below.")
            values = {}
            for key, col in [("persons", 4), ("males", 7), ("females", 8)]:
                value = cells[col]
                if isinstance(value, (int, float)) and value >= 0 and value == int(value):
                    values[key] = int(value)
                else:
                    values[key] = None
                    flags.append(key.title() + " is not a numeric count in the source; original cell: " + str(value))
            if all(v is not None for v in values.values()) and values["persons"] != values["males"] + values["females"]:
                flags.append("Persons does not equal the reported male and female components.")
            records.append(dict(zip(["state_code", "district_code", "name"], identity), year=year, year_label=year_text, **values, variation=str(cells[5]).strip(), percentage=str(cells[6]).strip(), flags=flags, source_row=index+1, source_cells=cells))
        elif any(str(cell).strip() for cell in cells):
            if year_text:
                raise ValueError("Unrecognised year at row " + str(index+1))
            notes.append({"source_row": index+1, "text": " ".join(str(cell).strip() for cell in cells if str(cell).strip())})
    keys = [(r["state_code"], r["district_code"], r["year"]) for r in records]
    if len(set(keys)) != len(keys) or not set(range(1901, 2012, 10)).issubset({r["year"] for r in records}):
        raise ValueError("Duplicate identity or unexpected historical coverage")
    provenance = {key: manifest[key] for key in ["name", "url", "landing", "sha256", "retrieved_at", "boundary_basis"]}
    return {"source": provenance, "sheet": "A-2", "records": records, "notes": notes}


if __name__ == "__main__":
    manifest = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
    result = extract(sys.argv[1], manifest)
    Path(sys.argv[3]).write_text(json.dumps(result, ensure_ascii=True, indent=2), encoding="utf-8")
    print(str(len(result["records"])) + " records; " + str(len(result["notes"])) + " source note lines")
