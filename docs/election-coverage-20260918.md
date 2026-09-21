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


## Historical download completion and scan extraction

Haryana historical collection finished with all 724 discovered report records preserved. The separate 2004 PC and 2005 AC selection failures remain unresolved; this is not all-year completeness. Goa/Bihar extraction and the initial Sikkim extraction pass finished. A full national extraction batch is running.

The 15 Sikkim files yielded no mapped polling rows in the initial pass. Visual review confirmed that the first report is an image scan. An English OCR pass is running over the scanned pages, preserving original PDFs and recording word coordinates, confidence, and review warnings. The first checked page contains recognition errors and is marked needs_visual_review; OCR text is not being treated as verified vote totals. OCR now also reads supplemental dropdown manifests and streams original-file checksums, covering the newer archive collectors without loading large PDFs wholly into memory.

ECI CEO contact details identify https://ceoelection.mp.gov.in/ as the current Madhya Pradesh website, while the earlier directory URL no longer resolves. This verified entry point has been added to the collector. Discovery is running and has exposed historical report links including 2009 PC reports; preservation and coverage checks are still pending.


## Nagaland retry completion

The next Nagaland retry recovered all 18 remaining reports. All 295 report records exposed by the preserved dropdown responses now have original files. This completes that discovered download set, not every historical election or constituency. Earlier sparse selections and extraction/OCR limitations still apply.

The latest public index checkpoint contains 16,390 preserved document entries, 4,536 extracted documents and 229,971 mapped polling rows. There are 1,251 queued discovery pages, including newly discovered archive links. Madhya Pradesh discovery, national extraction and Sikkim OCR remain active. No live deployment has been performed.


## Sikkim OCR pass completed

The Sikkim OCR pass finished all 81 pages marked scanned or empty by the table extractor. Fifty-five pages are labelled unverified_ocr and 26 need closer visual review; neither label means verified election results. Original files and OCR word coordinates, engine/model hashes and page warnings are preserved. A Goa OCR pass has now started against its extracted scanned pages, while the national table extraction batch continues.

The additional oversized Rajasthan report (Form20-19.pdf from the 2023 Assembly archive, discovered through the Delhi directory) was recovered: 108,064,920 bytes. Daman still fails certificate verification and remains a source gap. Madhya Pradesh discovery/downloads remain active; this checkpoint is not national completeness.


## Goa OCR completion and Bihar continuation

Goa OCR completed 489 scanned pages: 298 labelled unverified_ocr and 191 needing visual review. These are OCR quality labels, not verification of election results. Original files, word positions and quality warnings remain preserved. Bihar English/Hindi OCR started after the Goa process exited; its initial checkpoint is 91 pages. Only one OCR batch is running alongside the two-worker national table extraction batch.

The last completed index rebuild counted 16,522 document entries, 5,805 extracted documents and 317,002 polling rows. Madhya Pradesh has 137 preserved reports out of 167 discovered document records, with further discovery pages queued. Collection and extraction are still incomplete, and no live deployment has been performed.


## Bounded retries for unavailable archive pages

Automatic page discovery now stops retrying a URL after three failed passes. Its URL, final error, attempt count and exhausted status remain in the manifest and source warnings; failed pages are not reclassified as complete or silently discarded. An explicit rediscovery pass can retry them and clears the failure record only after recovery. Original manifest snapshots remain preserved. A regression test covers exhaustion, retained evidence and successful explicit recovery.

The latest completed index checkpoint has 16,528 document entries, 6,352 extracted documents and 363,366 polling rows. National extraction and Bihar OCR remain active. Queued discovery and unavailable official sources still prevent a final completeness claim or deployment package.


## Bounded failed-document retries

Repeated file download failures now also stop automatic retry after three failed passes, preserving the URL, error, count and retry-exhausted marker. Explicit rediscovery can retry them; successful recovery clears stale error markers and preserves the recovered original. Regression tests cover page and document exhaustion, evidence retention and explicit recovery. This changes retry behaviour, not the definition of completed coverage.

The last completed public index contained 16,682 document entries, 7,060 extracted documents and 415,878 polling rows. Bihar OCR reached 361 pages; Madhya Pradesh reached 139 preserved reports. Both the national extraction and remaining discovery queues are still active.


## Keep result discovery out of adjacent news navigation

The old ECI archive was expanding the queue through Previous/Next File links into media-coverage notices, election schedules and observer briefings. The polling collector now defers these specifically identified adjacent notices unless their labels identify results, statistics or Form 20. Directly linked result archives remain eligible. Deferred URLs, labels, referring pages and reasons remain preserved in the manifest; no source files are deleted. Tests cover preserved deferred evidence and continued eligibility of result/statistical links. National extraction, Bihar OCR and remaining result discovery continue.


## Retired HTTP links, unreliable text layers and recovered sources

The resumed batch had stopped at a usage limit while extraction was still pending. Every preserved document was re-verified, the 2,003 documents still on older adapters were remapped to the current grid adapter, and the public index now publishes 30,303 document entries, 30,301 extracted documents and 1,552,923 polling rows with no queued discovery pages.

Some official pages still publish plain-HTTP file links after retiring HTTP. A failed `http` source is now retried once over `https` before a failure is recorded, so a recovered original keeps its effective HTTPS URL and checksum. Chandigarh's Form 20 results for 2004, 2009 and 2014 (1,800,226, 8,399,114 and 10,545,069 bytes) were recovered this way; its 2019 report now returns HTTP 404 from the official site and remains a recorded gap. Focused tests cover the HTTPS recovery, the absence of a retry for a failing HTTPS source, and the preserved original.

Daman & Diu now holds 10 preserved documents, including a 70-page report, from the retained Form 20 entry point that previously failed. The empty source directories were re-checked against the live sites: Karnataka's numeric paths (`/285/form-20/kn`, `/213/elections/kn`, `/rtistats/kn`) return HTTP 404 site-wide while its static pages still serve, and Rajasthan, Dadra & Nagar Haveli and Ladakh fail name resolution or TLS. Those results are recorded as externally unavailable rather than as retryable download failures, and Arunachal Pradesh's 60 links remain HTTP 404.

Three Chandigarh reports carried a garbled embedded text layer, so the extractor treated them as readable text: the pages produced no polling rows and were never offered to OCR. Pages that yield no polling rows are now flagged when most of their alphabetic characters fall outside words of three or more letters. Pages that map rows measured at least 0.82 on that measure, while the affected scans measured below 0.6. Chandigarh's 2004 and 2014 reports were re-extracted and OCR'd, preserving 29 pages of unverified text: 11 pages labelled unverified_ocr from 2004, and 18 pages from 2014 of which 10 are unverified_ocr and 8 require visual review. OCR text stays unverified and no vote total is inferred from it.

The flagged backlog remains the largest task: 100,545 scanned or empty pages in 8,589 documents, 148,207 pages whose tables still need polling-row mapping, and 49,513 pages where no table grid was recognised. The unreliable-text check applies to documents as they are extracted or re-extracted, so the 29 pages recorded here are the current total rather than the corpus-wide count. Five source directories still hold no document: Andaman & Nicobar Islands, Arunachal Pradesh, Dadra & Nagar Haveli, Karnataka and Ladakh. National completeness is not established and no deployment has been performed.


## Polling rows carried across continuation pages

Form 20 tables that span several pages repeat the header only on the first page, so the later pages were preserved but mapped to no rows. The mapper now carries the resolved header, candidate names and total columns forward inside the same document when a headerless table has exactly the same width, and records `Column headers carried forward from the previous page of the same document.` on each page that uses them. Reconciled totals, unreadable cells and source cells keep the same handling as normally mapped rows, so a carried row is still flagged whenever its votes do not match the source total.

The remap of preserved cells processed 14,436 documents. Gujarat, which maps only partially, gained 6,614 rows (45,885 to 52,499) across 532 carried-header pages, with a warning profile close to that of its normally mapped rows. Nationally the public index now reports 1,925,054 polling rows, up from 1,552,923. Mapping stays resumable: documents already on the current mapper are skipped, and a document whose pages all map is never rewritten.

The same pass showed a second layout that still maps nothing: Punjab's `Final Result as Per ENCORE` sheets carry no Form 20 grid header at all, so continuation cannot help them and they need a separate adapter. A `--state` remap now resolves its folders from the preserved catalogue instead of reading every manifest.
