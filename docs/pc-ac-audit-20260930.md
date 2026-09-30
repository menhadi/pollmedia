# PC and AC source audit — 30 September 2026

Scope: general-election PC and AC statistical-report editions. The [official ECI statistical-reports catalogue](https://www.eci.gov.in/statistical-reports) was saved at `exports/eci-statistical-reports-20260930.source.json` (SHA-256 `08354902406541b6ba131faf44cf7b588775a63936b3d19f98b7f3d0ee1b9ae3`). Its 21 PC and 427 AC links exactly match the distinct saved catalogue URLs. The 18 Uttar Pradesh URLs repeated across two fixture lists represent the same source editions and are deduplicated by URL; they are not duplicated database seats.

The local post-repair archive has **448 extracted editions and 74,218 constituency records**: 9,896 PC and 64,322 AC. Before these repairs, the live historical index was checked at 74,191. No repeated record code within an edition or extraction URL/kind/year mismatch was found. The official catalogue establishes available report editions; it does not prove every possible historical election year or constituency has a published report.

## Source-backed corrections prepared locally

Nine edition extractions were revised after SHA-256 verification of their original official PDFs. Their previous exact JSON bytes are preserved as `extraction-<old SHA-256>.json` beside the originals. Existing constituency records were unchanged except for four corrected vote fields and their source evidence. No original PDF, raw table, OCR output, or warning was deleted.

| Official edition | Recovered seat numbers | Candidate evidence |
| --- | --- | --- |
| Delhi AC 1951 | 30 | 5 rows, sum matches printed valid votes |
| Mysore AC 1951 | 13–15 | 15 rows, each seat sum matches |
| Sourastra AC 1951 | 1, 29–30, 37–39, 44–48 | 44 rows, each seat sum matches; multi-member warnings retained |
| Tamil Nadu AC 1991 | 132–135 | 37 rows from PDF page 293, each seat sum matches |
| Assam AC 1951 | 25–27 | Official summary marks uncontested; candidate cells blank |
| Assam AC 1967 | 1 | Official summary marks uncontested; candidate cells blank |
| Assam AC 1983 | 32, 71, 75 | Official summaries mark uncontested; candidate cells blank |
| Gujarat AC 1975 | 63 Viramgam | Official PDF page 77 marks uncontested; candidate cells blank |

These are **27 recovered seat records**: 19 with 101 source-backed candidate rows, and eight uncontested records with no invented candidate or vote. Their status remains `needs_review`. The Gujarat 1975 PDF displayed zero electors on the uncontested summary page; the new record leaves `electors` unset rather than presenting that as a verified electorate.

Gujarat AC 2012 had 145 candidate vote cells blank. Four exact candidate names and printed totals match the preserved official Form 20 totals, at least two other candidate totals on each total row, and the existing general-plus-postal components: Abdasa **715**, Rapar **5,157**, Tharad **14,074** and **4,036**. Each corrected candidate now stores the official Form 20 URL, original PDF SHA-256, page/table/column and preserved page SHA-256. The source records remain `needs_review`. **141 Gujarat 2012 vote cells** and **348 AC vote cells overall** remain blank and need source-specific review.

Two PC records still have no candidate rows: 1967 New Delhi and South Delhi (`d7365c7939cfc38b09eb9581`, codes 500–501). Visual inspection of official Vol I PDF page 187 showed overprinted/clipped party and vote columns; Vol II pages 499–500 provide winner/runner summaries but not full candidate rows. No votes were inferred from percentages or third-party sources. The eight recovered uncontested AC records are also candidate-empty because their official summary candidate cells are blank. Thus the post-repair inventory has **10 candidate-empty records**, all explicitly under review.

The read-only audit flags **20 AC editions with internal constituency-number gaps** as review leads, available through `python pilot/audit_pc_ac_gaps.py --detail`. A gap is not by itself an omitted contest: some reports explicitly exclude seats or have no poll. There are **167 same-name seat occurrences under different official numbers**; they are distinct records and were not deleted. The website fix now keeps an exact edition and seat code when navigating such names, so it does not combine their results.

Candidate rows being present does not certify every value: **1,212 PC and 52,173 AC records** still carry `needs_review` warnings, commonly for independent summary reconciliation. These archive records are not promoted to accepted election contests.

## Guarded data-only import

The local bundle [pollmedia-pc-ac-corrections-20260930.zip](../exports/pollmedia-pc-ac-corrections-20260930.zip) contains 18 checksum-verified one-file packages: nine exact old snapshots and nine revisions, plus `SHA256SUMS` and `IMPORT.sh`. Bundle SHA-256: `950ea36e40d71b09180fd1658fa9aea3b3a4f985e5739cc3f6371720e713175f` (559,096 bytes). It has **not yet been transferred or imported to the live server**.

After transferring the bundle to `/home/pollmedia/tmp/` on the server, use these exact commands:

```bash
cd /home/pollmedia/tmp
echo '950ea36e40d71b09180fd1658fa9aea3b3a4f985e5739cc3f6371720e713175f  pollmedia-pc-ac-corrections-20260930.zip' | sha256sum -c -
mkdir -p pc-ac-corrections-20260930
unzip -n pollmedia-pc-ac-corrections-20260930.zip -d pc-ac-corrections-20260930
cd pc-ac-corrections-20260930
bash IMPORT.sh
```

`IMPORT.sh` holds a single-run lock, verifies every inner package checksum, checks at least 10 GiB free server disk before and during the import, validates and imports all previous snapshots first, then validates and applies exact-hash guarded revisions. It finally checks for **74,218 records across 448 editions** before rebuilding the constituency index. Run when no other election import is active and after confirming the live code already includes `archive:import-json --allow-revision`; the script does not pull code or run migrations. Check the import command output and live database afterward. This package changes election archive JSON only; it has no census or polling-source import.
