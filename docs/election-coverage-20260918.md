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

## Space cleared and additional historical sources

After the user freed space, D: had approximately 22 GB available. The pipeline now supports deferring a state whose earlier collector is still active while collecting other states immediately. Bihar's earlier collection and the existing Tamil Nadu extraction continue independently.

Goa's public ASP.NET election dropdown exposes 20 election selections, including PC, AC and by-elections, spanning 1967–2024. Submitting the source's “all constituencies” option returned 149 distinct report-viewer URLs. Each returned election selection was checked against the requested dropdown value. Downloads are ongoing; a dropdown option alone is not proof of a complete historical dataset. The PDFs are returned directly by viewer URLs without a `.pdf` extension.

The Goa collector preserves its HTML responses, selection labels, original PDFs, hashes and failures in an independent supplemental manifest. The public index, extractor and preservation exporter merge that manifest with ordinary discovery without overwriting its source files or the active crawl manifest. Live deployment remains pending completion and coverage review.

## Latest extraction checkpoint

The Goa dropdown collection finished with all 149 linked report files preserved. The earlier Bihar crawl finished with 2,379 documents, and the earlier Tamil Nadu extraction batch finished. A new Goa/Bihar extraction batch is running alongside national source discovery.

At the latest index rebuild there were 13,316 preserved document entries, 2,087 extracted documents and 168,692 mapped polling rows, with 670 discovery pages still queued. Source-directory overlap means these are not unique national reports or stations. Coverage is not yet complete.

Extraction batches now share an operating-system-held lock so a queued full batch waits for any active extraction to finish. Process exit releases the lock automatically. A concurrency test verifies waiting and release. Scanned, rotated or garbled source text remains flagged rather than being interpreted as reliable votes.

## Nagaland dropdown archive

The official Nagaland archive exposes 16 PC/AC general-election and by-election selections spanning 2003–2026. Reading the actual public dropdown responses discovered 295 source report records, including final result sheets and supporting reports. The first pass preserved 262 of 295 report records. The remaining 33 downloads timed out; a retry pass is running and skips verified preserved files. Some election selections expose only a small number of reports; these remain coverage gaps rather than complete constituency sets.

The archive serves files through POST forms containing source record IDs. The collector preserves the selection responses, record IDs, request fields, original PDFs and hashes. Supplemental manifest merging now keeps separate POST records even when their public source-page URL is identical. Downloads use the operating system's normal certificate verification and public session cookies; authentication is not required.

## Punjab archive discovery

The Punjab site exposes its archive through an “Elections” menu and numeric year dropdown values. The collector now follows the menu, recognises Vidhan Sabha/by-election navigation, and resolves that site's dropdown IDs using the official page's `electiondetails?id=…&fltr=…` routing. Numeric option values are no longer mistaken for standalone document URLs.

A fresh Punjab discovery pass is running. The 2024 PC selection exposes a “Part Wise Result- Form 20” page alongside constituency and assembly-segment results; further year selections remain queued. Discovery regression tests passed. This is additional source discovery, not a declaration of completed Punjab or national coverage.

## Large-file recovery

The failure audit found eight reports rejected by the ordinary downloader's 100 MB cap. A separate, single-process recovery pass now streams those PDFs to disk with a 512 MiB cap and a 15-minute request timeout. The first recovered Odisha report is 107,395,606 bytes. Remaining oversized downloads are still in progress and retain failure notes if unsuccessful.

Extraction checksum verification and ZIP preservation now stream source files rather than reading whole large PDFs into memory. Preserved files remain hash-verified, and corrupted existing copies are rejected. Focused download, preservation and parser tests passed.

The public index now keeps per-document page metadata in separate immutable, hash-verified files and loads only the selected report. At the checkpoint of 14,198 document entries, 3,253 extracted documents and 176,014 polling rows, the root index decreased from 15,318,551 to 8,487,197 bytes (about 45%). There were still 738 queued discovery pages. Older inline page metadata remains supported. Preservation packages include and verify the new metadata files. Three application tests (28 assertions), the archive preservation test and a local HTTP 200 check passed. Completion and live deployment remain pending.


## Sikkim dynamic archive

The official 2019 and 2024 Form 20 pages use an election-type dropdown backed by an AJAX endpoint. The collector now reads the supplied endpoint and election IDs, follows both assembly and parliamentary choices, and sends the required XMLHttpRequest header. The working non-www official domain replaces the certificate-failing www entry point.

All 15 linked report PDFs were preserved: eight files linked from the 2019 selections (including three named AC by-election reports) and seven from the 2024 selections. Original source URLs and SHA-256 checksums remain attached. Extraction is queued behind the active batch. Historical coverage outside these linked selections is not established. Four dropdown tests and thirteen existing parser/discovery tests passed.


## Haryana historical archive and remaining source gaps

The Haryana public booth-result page exposes POST endpoints for election types, available years and source file records. A dedicated collector preserves the raw responses, request fields, record IDs, labels and source metadata independently of the ordinary crawl. Discovery returned 724 records: 90 reports for each of the PC and AC selections in 2009, 2014, 2019 and 2024, plus four by-election reports from 2020, 2021, 2022 and 2024. Downloads are running. The listed 2004 PC and 2005 AC API selections failed with HTTP errors and remain recorded gaps.

The ordinary Haryana crawl now recognises constituency-named PDFs on pages whose title identifies Form 20; it preserved 94 files. These counts may overlap the historical API and do not imply unique reports. Seven focused dropdown tests and thirteen existing parser/discovery tests passed during this change.

Arunachal Pradesh links its 60 assembly reports through a numeric server address. The collector now accepts only the verified server and report path; all 60 linked downloads failed with HTTP errors in this pass (the checked first link returned 404). The links remain in the manifest for review. Parliamentary-election navigation is also followed.

All eight oversized reports in the recovery pass were preserved. The Nagaland retry recovered 15 additional reports, reaching 277 of 295 records; 18 records still have no preserved file. National collection and extraction continue, with live deployment pending.
