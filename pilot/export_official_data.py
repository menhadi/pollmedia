"""Preserve the local official-data collection as a checksummed ZIP snapshot."""
import argparse
import csv
from datetime import datetime, timezone
import hashlib
import io
import json
from pathlib import Path
import re
import sqlite3
import subprocess
import zipfile

TABLES = """authority_reviews census_catalogue_reviews census_catalogue_rows census_editions census_publications data_sources election_candidate_results election_contests election_import_batch_rows election_import_batches election_publications historical_election_reviews import_connectors import_publications import_runs indicators observations office_assignments office_jurisdictions offices official_source_hosts organizations people place_identifiers place_relationships places public_profiles report_drafts report_scopes source_checks source_releases""".split()
ROOTS = ["application/database/fixtures", "application/storage/app/private/census-archive", "application/storage/app/private/census-source-tables", "application/storage/app/private/election-archive", "application/storage/app/private/election-by-elections", "application/storage/app/private/election-batches", "application/storage/app/private/election-imports", "application/storage/app/private/official-imports", "application/storage/app/private/report-drafts", "application/storage/app/maps", "pilot/raw", "pilot/data"]


def export(root, destination):
    root = root.resolve()
    destination = destination.resolve()
    if destination.exists():
        raise ValueError("Snapshot already exists; choose a new output name")
    destination.parent.mkdir(parents=True, exist_ok=True)
    entries, counts, links, skipped = [], {}, set(), []
    stamp = datetime.now(timezone.utc).isoformat()
    revision = subprocess.check_output(["git", "rev-parse", "HEAD"], cwd=root, text=True).strip()
    db = sqlite3.connect((root / "application/database/database.sqlite").as_uri() + "?mode=ro", uri=True)
    db.execute("BEGIN")
    available = {row[0] for row in db.execute("SELECT name FROM sqlite_master WHERE type='table'")}
    missing = set(TABLES) - available
    if missing:
        raise ValueError("Missing expected data tables: " + str(sorted(missing)))
    temporary = destination.with_suffix(".partial")
    if temporary.exists():
        raise ValueError("An incomplete snapshot already exists at " + str(temporary))

    def find_links(text, origin):
        for url in re.findall(r'https?://[^\s<>"\\]+', text):
            links.add((url.rstrip(".,;)'"), origin))

    with zipfile.ZipFile(temporary, "w", compression=zipfile.ZIP_DEFLATED, compresslevel=1, allowZip64=True) as archive:
        def write_bytes(name, body):
            archive.writestr(name, body)
            entries.append({"path": name, "bytes": len(body), "sha256": hashlib.sha256(body).hexdigest()})

        for table in TABLES:
            cursor = db.execute('SELECT * FROM "' + table + '" ORDER BY rowid')
            columns = [column[0] for column in cursor.description]
            digest = hashlib.sha256()
            size = count = 0
            name = "tables/" + table + ".jsonl"
            # JSON Lines is lossless, including nested source payloads and nulls.
            with archive.open(name, "w", force_zip64=True) as output:
                for values in cursor:
                    record = dict(zip(columns, values))
                    text = json.dumps(record, ensure_ascii=False)
                    body = (text + "\n").encode("utf-8")
                    output.write(body)
                    digest.update(body)
                    size += len(body)
                    count += 1
                    for key, value in record.items():
                        if isinstance(value, str):
                            find_links(value, name + ":" + str(record.get("id", count)) + ":" + key)
            entries.append({"path": name, "bytes": size, "sha256": digest.hexdigest()})
            counts[table] = count
        db.rollback()
        db.close()
        for relative in ROOTS:
            folder = root / relative
            if not folder.exists():
                skipped.append({"path": relative, "reason": "not present"})
                continue
            for path in sorted(folder.rglob("*")):
                if not path.is_file():
                    continue
                name = path.relative_to(root).as_posix()
                if path.is_symlink() or path.suffix in [".tmp", ".sqlite", ".sqlite3"]:
                    skipped.append({"path": name, "reason": "temporary file, database or symlink"})
                    continue
                before = path.stat()
                digest = hashlib.sha256()
                size = 0
                with path.open("rb") as source, archive.open("files/" + name, "w", force_zip64=True) as output:
                    while chunk := source.read(1024 * 1024):
                        output.write(chunk)
                        digest.update(chunk)
                        size += len(chunk)
                after = path.stat()
                if (before.st_size, before.st_mtime_ns) != (after.st_size, after.st_mtime_ns):
                    raise ValueError("Source changed during export: " + name)
                entries.append({"path": "files/" + name, "bytes": size, "sha256": digest.hexdigest()})
                if path.suffix == ".json":
                    find_links(path.read_text(encoding="utf-8-sig"), "files/" + name)
        buffer = io.StringIO(newline="")
        writer = csv.writer(buffer)
        writer.writerow(["url", "record_location"])
        writer.writerows(sorted(links))
        write_bytes("source-links.csv", buffer.getvalue().encode("utf-8-sig"))
        manifest = {"created_at": stamp, "completed_at": datetime.now(timezone.utc).isoformat(), "git_revision": revision, "scope": "Local collected official data; not a claim of complete national coverage or a live-server backup", "format": "UTF-8 JSON Lines for database tables, original source files, CSV source link index", "preservation": "All exported table rows retain statuses, years, nulls, discrepancy notes and source relationships. Database rows use one read transaction. Source files are checked for changes during copying.", "excluded": "Accounts, passwords, sessions, API settings, queues, private citizen submissions and SEO operational tables", "table_rows": counts, "skipped_files": skipped, "source_link_locations": len(links), "files": entries}
        archive.writestr("manifest.json", json.dumps(manifest, indent=2).encode("utf-8"))
    temporary.rename(destination)
    # Re-read every member, checking both ZIP CRC and the preserved SHA-256.
    with zipfile.ZipFile(destination) as archive:
        for entry in entries:
            digest = hashlib.sha256()
            with archive.open(entry["path"]) as source:
                while chunk := source.read(1024 * 1024):
                    digest.update(chunk)
            if digest.hexdigest() != entry["sha256"]:
                raise ValueError("Verification failed: " + entry["path"])
    digest = hashlib.sha256()
    with destination.open("rb") as source:
        while chunk := source.read(1024 * 1024):
            digest.update(chunk)
    destination.with_suffix(".sha256").write_bytes((digest.hexdigest() + "  " + destination.name + "\n").encode("ascii"))
    destination.with_suffix(".manifest.json").write_text(json.dumps(manifest, indent=2), encoding="utf-8")
    print(json.dumps({"archive": str(destination), "bytes": destination.stat().st_size, "files_verified": len(entries), "table_rows": sum(counts.values()), "source_link_locations": len(links), "sha256": digest.hexdigest()}))


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("--root", type=Path, default=Path(__file__).resolve().parents[1])
    parser.add_argument("--output", type=Path, required=True)
    args = parser.parse_args()
    export(args.root, args.output)
