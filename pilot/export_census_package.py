"""Package published Census extractions and originals without database IDs or accounts."""
import argparse
import hashlib
import json
from pathlib import Path
import sqlite3
import zipfile


def export(root, destination):
    if destination.exists():
        raise ValueError("Choose a new package filename")
    db = sqlite3.connect((root / "application/database/database.sqlite").resolve().as_uri() + "?mode=ro", uri=True)
    db.row_factory = sqlite3.Row
    records = db.execute("""SELECT e.source_key, e.row_count, r.source_url, r.sha256,
        r.raw_path, r.extracted, r.created_at, c.options, c.format
        FROM census_publications p JOIN census_editions e ON e.id=p.edition_id
        JOIN import_runs r ON r.id=e.import_run_id
        JOIN import_connectors c ON c.id=r.import_connector_id ORDER BY e.source_key""").fetchall()
    if not records:
        raise ValueError("No published Census editions")
    manifest = {"version": 1, "sources": []}
    destination.parent.mkdir(parents=True, exist_ok=True)
    with zipfile.ZipFile(destination, "x", zipfile.ZIP_DEFLATED) as archive:
        for record in records:
            key = record["source_key"]
            raw = (root / "application/storage/app/private" / record["raw_path"]).read_bytes()
            if hashlib.sha256(raw).hexdigest() != record["sha256"]:
                raise ValueError("Source checksum mismatch: " + key)
            extracted = record["extracted"].encode("utf-8")
            item = {"key": key, "source_url": record["source_url"], "sha256": record["sha256"],
                    "extracted_sha256": hashlib.sha256(extracted).hexdigest(),
                    "retrieved_at": record["created_at"], "options": json.loads(record["options"]),
                    "row_count": record["row_count"]}
            manifest["sources"].append(item)
            archive.writestr(key + "." + record["format"], raw)
            archive.writestr(key + ".json", extracted)
        archive.writestr("manifest.json", json.dumps(manifest, indent=2))
    db.close()
    digest = hashlib.sha256(destination.read_bytes()).hexdigest()
    destination.with_suffix(".sha256").write_bytes((digest + "  " + destination.name + "\n").encode("ascii"))
    print(json.dumps({"sha256": digest, "sources": len(records), "rows": sum(r["row_count"] for r in records)}))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("destination", type=Path)
    args = parser.parse_args()
    export(Path(__file__).resolve().parents[1], args.destination)
