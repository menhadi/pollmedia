# Assembly result visibility and source corrections — 1 October 2026

The earlier chart-hiding change was reverted in commit `077753a`. No election source or database rows were deleted. Commit `020c625` keeps all election years visible and displays internally reconciled candidate comparisons, with source notes and direct result-table links where turnout is still unverified. Commit `b3d8283` added a guarded source-correction package builder.

The archived PC/AC source inventory contains 74,218 constituency records in 448 editions: 64,322 AC and 9,896 PC. Exactly 7,548 AC records from detailed official PDFs had complete candidate rows but no imported `votes_polled` value and were marked pending independent summary reconciliation. The data-only bundle `exports/pollmedia-ac-summary-corrections-20261001-v5.zip` matches **7,130** of them against explicitly labelled summary-page voters, valid candidate votes, and, where printed separately, NOTA. It covers **55 official editions**. Of those, **6,985** match the summary elector count exactly. The other **145** have a documented elector-count difference of no more than 0.05%; both original counts are kept, and the record remains marked for review. For example, Karnataka 2013 Nippani has 189,696 electors in the detailed result and 189,698 in the summary. Its candidate votes match the summary, and turnout uses the summary's 189,698 elector denominator with a † note. In 900 records the official summary is a separate PDF in the same preserved edition; its filename and SHA-256 are recorded on the corrected record. The candidate rows, source URL, source file, source hash, status and original warnings remain preserved; the prior JSON bytes are included as SHA-named snapshots. No record is promoted to an accepted contest.

The remaining **418** candidate-only detailed-PDF records are left unchanged: 45 have conflicting vote totals, 359 have no parsed summary, and 14 have a constituency-name conflict. Their existing candidate tables remain visible with notes. Ambiguous workbook turnout values are also left unchanged; their candidate comparisons remain accessible. These are source-review gaps, not zero votes or deleted data. The bundle does not upload PDFs or alter R2 links.

The bundle is 8,212,547 bytes with SHA-256 `b24df99fec744944e63744e266f5f8f77b562ad5adc08600d7be13910510ef23`. Verification compared all 55 old and new JSON pairs: record counts, non-record metadata, candidate rows and review status are identical; only warning provenance and source-corroborated summary fields changed. All 110 inner ZIP checksums match. The focused Python tests and 30 PHP feature tests pass. Public PC/AC pages show reported winners and margins from warning-marked records with † and links to the specific data note. This changes display and source summaries; it does not add rows to the separate accepted-contest table.

## Live release

From Windows PowerShell, upload the data-only bundle:

```powershell
scp "D:\pollmedia\exports\pollmedia-ac-summary-corrections-20261001-v5.zip" root@mail.pollmedia.org:/home/pollmedia/tmp/
```

Then, in the server terminal, with no other election import active:

```bash
git -c safe.directory=/home/pollmedia/app -C /home/pollmedia/app pull --ff-only origin main
git -c safe.directory=/home/pollmedia/app -C /home/pollmedia/app rev-parse --short HEAD
php8.4 /home/pollmedia/app/application/artisan view:clear
cd /home/pollmedia/tmp
echo 'b24df99fec744944e63744e266f5f8f77b562ad5adc08600d7be13910510ef23  pollmedia-ac-summary-corrections-20261001-v5.zip' | sha256sum -c -
mkdir -p ac-summary-corrections-20261001-v5
unzip -n pollmedia-ac-summary-corrections-20261001-v5.zip -d ac-summary-corrections-20261001-v5
cd ac-summary-corrections-20261001-v5
bash IMPORT.sh
```

`IMPORT.sh` verifies inner checksums, requires at least 10 GiB free server disk, refuses local JSON that would shadow the DB revision or existing review overlays, imports old snapshots, checks each exact prior SHA before revision, and checks/rebuilds the constituency index. The expected index remains **74,218 records in 448 editions**. If any guard fails, stop and inspect that precise edition rather than bypassing the check.

Use v5 in place of v4 when v4 has not been imported. If v4 has already been imported, this package's prior-SHA guard will stop; an incremental v4-to-v5 package is required. Do not bypass that guard or reimport the old snapshot over a newer revision.
