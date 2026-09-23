# Archived PDF R2 checkpoint — 23 September 2026

The polling-source uploader is active and owns the single R2 upload lock. Do not run a second uploader concurrently. `pilot/sync_archived_pdfs_r2.py` prepares a later phase under the same lock, with separate `exports/archived-r2-receipts.jsonl` receipts. It reads the Windows user-encrypted credential only through the existing private wrapper environment. Original PDFs and manifests stay on the local disk. An upload receipt is written only after local SHA-256, R2 object metadata and R2 read-back SHA-256 agree.

Local manifest inventory: 1,838 PDFs (738,320,644 bytes): 1,435 election archive, 402 by-election and 1 census. Their R2 keys retain category prefixes under `pollmedia/`. This is an inventory, **not** an upload or national coverage count.

There are 252 additional election-archive PDFs (9,982,720 bytes) in four archive folders that are absent from their current `manifest.json` file lists. The uploader intentionally excludes them because those manifests do not supply a matching PDF hash and official source URL. Preserve these files and investigate their provenance separately; do not infer their source from similar filenames. The full disk inventory in these three categories is 2,090 PDFs.

After the polling uploader has exited, run the archived uploader using the same credential-loading wrapper. Do not copy credentials into the repository or print them. The archive uploader resumes from receipts, checks the original PDF hash before upload and uses the shared upload lock. This R2 phase is independent of PostgreSQL data-only imports; no live database migration or import is part of this checkpoint.
