# Assembly result visibility and source corrections — 1 October 2026

The earlier chart-hiding change was reverted in commit `077753a`. No election source or database rows were deleted. Commit `020c625` keeps all election years visible and displays internally reconciled candidate comparisons, with source notes and direct result-table links where turnout is still unverified. Commit `b3d8283` added a guarded source-correction package builder.

The archived PC/AC source inventory contains 74,218 constituency records in 448 editions: 64,322 AC and 9,896 PC. Exactly 7,548 AC records from detailed official PDFs had complete candidate rows but no imported `votes_polled` value and were marked pending independent summary reconciliation. The final data-only bundle `exports/pollmedia-ac-summary-corrections-20261001-v4.zip` independently matches **6,985** of them against explicitly labelled summary-page electors, voters, valid candidate votes, and, where printed separately, NOTA. It covers **55 official editions**. In 900 records the official summary is a separate PDF in the same preserved edition; its filename and SHA-256 are recorded on the corrected record. The candidate rows, source URL, source file, source hash, status and original warnings remain preserved; the prior JSON bytes are included as SHA-named snapshots. No record is promoted to an accepted contest.

The remaining **563** candidate-only detailed-PDF records are left unchanged where source summary text is unreadable or contradicts a recorded field (for example Karnataka 2013 elector totals). Ambiguous workbook turnout values are also left unchanged; their candidate comparisons remain accessible. These are source-review gaps, not zero votes or deleted data. The bundle does not upload PDFs or alter R2 links.

The bundle is 8,203,381 bytes with SHA-256 `46dffe1cce1c6e31d879a063eb1e26c24460a6cbb0c10813fd3bebb3bc595439`. Verification compared all 55 old and new JSON pairs: record counts, non-record metadata, candidate rows and review status are identical; only warning provenance and source-corroborated summary fields changed. All 110 inner ZIP checksums match. The focused Python tests and 9 PHP feature tests pass.

## Live release

From Windows PowerShell, upload the data-only bundle:

```powershell
scp "D:\pollmedia\exports\pollmedia-ac-summary-corrections-20261001-v4.zip" root@mail.pollmedia.org:/home/pollmedia/tmp/
```

Then, in the server terminal, with no other election import active:

```bash
git -c safe.directory=/home/pollmedia/app -C /home/pollmedia/app pull --ff-only origin main
git -c safe.directory=/home/pollmedia/app -C /home/pollmedia/app rev-parse --short HEAD
php8.4 /home/pollmedia/app/application/artisan view:clear
cd /home/pollmedia/tmp
echo '46dffe1cce1c6e31d879a063eb1e26c24460a6cbb0c10813fd3bebb3bc595439  pollmedia-ac-summary-corrections-20261001-v4.zip' | sha256sum -c -
mkdir -p ac-summary-corrections-20261001-v4
unzip -n pollmedia-ac-summary-corrections-20261001-v4.zip -d ac-summary-corrections-20261001-v4
cd ac-summary-corrections-20261001-v4
bash IMPORT.sh
```

`IMPORT.sh` verifies inner checksums, requires at least 10 GiB free server disk, refuses local JSON that would shadow the DB revision or existing review overlays, imports old snapshots, checks each exact prior SHA before revision, and checks/rebuilds the constituency index. The expected index remains **74,218 records in 448 editions**. If any guard fails, stop and inspect that precise edition rather than bypassing the check.
