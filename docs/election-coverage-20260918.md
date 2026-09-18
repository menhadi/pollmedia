# Election publication and remaining coverage — 18 September 2026

Scope: all available Indian PC, AC and by-election history, plus polling-station/Form 20 sources. Source flags do not block publication: show † with an explanation and official provenance. Missing votes stay missing, and uncertain source identities are not silently mapped to current constituencies.

## Collected snapshots

- General elections: 427 AC source editions (64,295 constituency tables; 541,800 candidate/NOTA rows) and 21 PC editions (9,896 tables; 109,772 candidate rows), relative to the collected ECI catalogue. Overlapping editions are not unique elections.
- By-elections: 2,459 structured constituency records and 18,766 candidate rows. Historical winner/runner summaries are explicitly labelled as incomplete candidate coverage.
- Polling sources at this checkpoint: 4,725 preserved documents, 1,211 extracted documents and 68,512 mapped source rows. These are not unique polling stations. Collection and extraction continue.

## Corrected by-election package

Use `pollmedia-by-election-results-20260918-v2.zip`, SHA-256 `c9746a7e60a4970df65b7a2923852cc5e6d5d61f66087573f85ec5de4030fce5`, instead of the earlier package without `-v2`. The older snapshot remains preserved for audit.

The historical workbook mixes four-digit years with dates such as `9.3.57`. The corrected parser reads those dates, preserves their original labels, and publishes the one unclear date under “Year unclear †” instead of inheriting an earlier year. Source documents remain unchanged.

## Publication and gaps

PC/AC/by-election records with review notes remain visible. Polling originals can be downloaded before their table extraction finishes, with checksum verification. Poor OCR is labelled and does not become inferred vote counts.

National completeness has not been established. Gaps include two clipped 1967 Delhi PC source tables, unavailable by-election links, scanned or unmapped polling tables, and state/year sources still being discovered. The 37 source-directory entries include historical labels; they are not a claim of 37 current states/UTs. A source-directory label indicates where a document was found, not necessarily its jurisdiction.

The local pipeline waits for existing collection jobs, rediscovers official sources, waits for existing extraction, extracts remaining PDFs, remaps tables, rebuilds the public index, audits coverage and creates a verified ZIP. Its status is in `application/storage/app/private/polling-station-sources/pipeline-status.json`. A completed batch does not establish national completeness.

Deployment remains user-controlled: code is pushed to Git; packages are staged separately. No live import or deployment is performed by this batch.

## Continued collection and spreadsheet extraction

The user will deploy only after the remaining work is complete. The current batch is still running and is not a deployment-ready completion declaration.

The polling extractor now reads XLS/XLSX worksheets alongside PDFs. It retains sheet names and original row positions, preserves formula text when available, and warns that legacy XLS stored results may be stale. Centered headings and numbered station names are supported; assembly-segment totals and postal-ballot rows stay in the raw tables instead of becoming polling-station rows. The v4 mapper recovered 10,463 rows in 20 Jammu & Kashmir source documents; overlapping reports are not deduplicated elections.

Discovery now handles official links containing backslashes and file-download endpoints without PDF extensions. Verified supplementary entry points include the migrated West Bengal and Himachal Pradesh domains. The Himachal Pradesh 2022 Form 20 page exposes 68 constituency documents. Failed certificate checks remain recorded rather than bypassed.

After the first discovery pass, the pipeline drains remaining page queues while they make progress and archive disk space stays above its reserve. All extracted tables, outstanding URLs and access failures remain part of the final coverage review. Tests cover numeric/blank/formula distinctions, centered headings, named polling stations, exclusion of aggregate rows, source download integrity and worksheet labels.
