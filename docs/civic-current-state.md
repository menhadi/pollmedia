# Current authorization — collection resumed 29 September 2026

User explicitly requested resuming remaining exploration/extraction under the original scope, faster where feasible. This supersedes ALL older pause-only instructions below. Both existing civic five-minute crons were re-enabled at 10:06 UTC; no other crons changed. The 15-minute monitor was updated to collection/preparation/release scope. Original plan: civic-server-work-plan-20260925.md; retain adaptive two-worker admission, caps, low priority, TLS and original-source preservation. No renewed expired CPU trial or relaxed extraction CPU gate. App deployment/migrations remain user-managed; elections excluded.

Resume receipt: /home/pollmedia/census-worker/releases/civic-20260929/extraction-resume.json. Exact crontab backup recorded there. Queue audit: primary 521 complete, OCR 488 complete, no pending jobs; feeder zero pending downloads/workbooks, one catalogue requiring source review (43938, no supported PDF link). A West Bengal town XLSX URL returned non-ZIP content previously; preserve as source failure, do not mislabel parsed data. Current sample RAM 5,973 MiB, load 8.04 on six CPUs, swap/PSI zero: extraction admission waits for load <=6. Scheduling enabled does not mean actively extracting.

Exact next action: expand supported official sources against coverage gaps, prioritizing machine-readable Census/LGD/data.gov.in, then education/health/water/livelihood releases per original source matrix. Do not duplicate completed registry jobs. Investigate catalogue 43938 format and the non-ZIP workbook response; do not bypass verification or retry indefinitely. LGD/data.gov.in catalogue discovery was attempted this turn but web fetch timed out; no new dataset acquired. Preserve original URLs/hashes/period and source-era identifiers. Reference XLSX refresh and admin confirmation/local-original-download UI remain unfinished release work. Existing published releases remain live with review flags.

### Latest check — 29 September 10:11 UTC

Both resumed crons checked at 10:10 and report waiting_for_resources (load 8.12 / six CPUs, RAM 6,323 MiB, swap/PSI zero). Primary 521 complete; OCR 488 complete; no pending extraction jobs. Investigated Saharanpur 2001 catalogue 43938: preserved HTML has no download link of any format. Verified-TLS metadata export acquired (1,262 bytes), SHA-256 4394d39dff2a1a5151aa1ac57c301c68bb3449d8e37d22311d6a207928de3fb4. Metadata identifies DH_09_2001_SAH.pdf and publication date 2006-10-17, but resources is empty and file_uri is only a filename, not a verified download URL. Do not guess a URL or call this book collected. Receipt source-gap-review-20260929T1011.json in worker releases/civic-20260929 includes original catalogue HTML hash and metadata provenance. This resolves the parser-format question: no supported link is present in this evidence.

Official data.gov.in search confirms the LGD catalogue and district resource landing page; direct web fetches timed out. Exact downloadable resource/API access still unverified. Next action: inspect the official LGD resource through a supported browser/API path, preserve exact download URLs/schema/access requirements; in parallel planning prioritize further supported Census sources rather than repeated unchanged Saharanpur retries. No new bulk dataset or publication this check. Reference XLSX/admin UI remain unfinished.

---

# Civic work: read this first

Updated 29 September 2026. This is the current operational handoff, not a replacement for the agreed product specification. Keep it short and update it after each material result. Read this file and the referenced release document before work; do not reread the entire chat or chronological checkpoint by default.

## Objective and agreed method

Pilibhit was the pilot for a repeatable, broader civic-data pipeline. It is not the final geographic scope. Follow the same method across supported districts/states: official source registration → preserved original and URL/date/hash → source-specific extraction → reconciliation against the original → Census/LGD identity and boundary-period review → indicator definition/units/missingness review → versioned import → website and source-reference verification.

Original scope: `platform-roadmap.md`, `data-source-feasibility.md`, `database-design.md`, `sync-and-sir-spec.md`, and `../pilot/README.md`. Current release instructions: `civic-existing-data-release-20260929.md`.

Collection expanded ahead of integration. Correct that delivery order now. More files, OCR batches or raw rows are not the release acceptance criterion. Do not narrow the broader project to a Pilibhit-only dataset or declare raw source tables to be finished development cards.

## Current authorization

- Keep both server extraction crons paused. No new acquisition, source discovery, OCR or new raw extraction.
- Prepare and import already collected civic data. Keep bulk preparation under `/home/pollmedia/census-worker`; local checkout is for scoped code/tests/Git and compact handoff artifacts.
- No election changes. No code deployment or migrations; user handles live code pull.
- User now explicitly authorizes publication of existing source evidence before admin confirmation. Publish pending-review labels and unresolved math/geography/period notes; do not block on semantic review or invent confirmed values/joins. Keep integrity verification. This does not authorize new extraction or code deployment.
- Do not resume collection after a partial import. First report what existing-data release actually completed and what remains.

## Evidence we have

| Evidence | Actual state |
| --- | --- |
| Five 2001/2011 Census views, 30,525 rows | Published as editions 1–5 on 29 September; counts, hashes, scopes and public pages verified. |
| 118 Census 1991 sources, 697,205 worksheet rows including definitions | Installed and public-page/CSV checks passed. Historical joins/indicators remain unapproved. |
| 68 amenity workbook validations | Original cells verified; reference years, field definitions and geography need interpretation. |
| 378 PDF evidence manifests | Not a unique-publication count. OCR/text is evidence, not accepted indicator data. 4,217 structured candidate rows remain unverified. |
| Official UP LGD export | Preserved source `https://lgdirectory.gov.in/downloadDirectory.do`, SHA-256 `3d8f2ad8754decb16e7b2f19512cf0526da186f2c6b4454271be2c10fb5e031e`. |
| Pilibhit code comparison | 879 explicit historical code pairs corroborated by LGD; 34 names require review. Diagnostic only, not accepted joins or unchanged-boundary proof. |
| data.gov.in | In original source plan. Zero matching source references in this civic staging database at the 29 September audit. Do not claim it was fully collected. |

Raw staging has 1,497,683 worksheet rows including headers, notes and overlapping records. Do not add this to filtered/1991 counts as a unique final total. No final approved national row count exists. Historical and geographic coverage are incomplete. The five 2001/2011 views are now live in the Census catalogue; the 1991 source-table bundle is also live as source evidence.

## Next deliverable, in order

1. Complete the first existing-data release described in `civic-existing-data-release-20260929.md`. Record scoped pre-state/backup, exact package hash, imported IDs/counts, flags, source references, website checks and recovery path. Keep source-table publication explicitly distinct from reviewed place indicators.
2. Maintain one coverage register by source, source-era geography, year, indicator/table, preserved original, validation state and publication state. Include LGD references: the first reference workbook was Census-focused and is not a complete civic-source inventory. Mark data.gov.in and unsupported sectors as gaps.
3. Review existing Census-to-LGD mappings and amenity field/date definitions. Publish only supported joins/indicators; keep unmatched and ambiguous records visible. Apply the pilot method district by district using already collected evidence, without restarting collection or limiting the final scope to Pilibhit.
4. Report the usable release and unresolved gaps. Only then plan the next collection batch against those gaps, including supported LGD/data.gov.in and department sources when collection resumes.

## Blockers and safeguards

On 29 September the user clarified that the CPU-load admission threshold is for extraction, not importing existing data. Existing-data imports may proceed one at a time at low priority even when load exceeds CPU count. Retain >=3 GiB starting RAM, swap <=8 MiB/sec, memory PSI some avg10 <=5%, disk >=10 GiB, 1.5 GiB address-space cap and ongoing RAM floor >=1.5 GiB. Record actual importer resource use. Do not alter extraction safeguards or unrelated services.

Fifteen relevant application tests and two bundle installer tests passed. Do not rerun completed raw-cell/OCR work. Narrow retesting to changed behavior or a concrete unresolved failure. Import and publication receipts now exist in the server release directory.

### Latest concrete step — 29 September, 08:12 UTC release

Imported and published the five-source 2001/2011 package: 30,525 rows; editions/import runs 1–5; publication pointers 1–5; zero population-component flags. Draft import took 16.10 seconds, sampled peak RSS 315,164 KiB (~308 MiB), followed by a 2.89-second publication pass. User clarification allows existing-data imports without the extraction CPU-load gate. RAM stayed above its floor; no swap or memory-pressure event was observed.

Receipts under `/home/pollmedia/census-worker/releases/civic-20260929/`: `draft-import-20260929T081006Z.json` (fresh empty scoped pre-state and runtime), its `.log`, `draft-review-20260929T0810.json` (counts/geographic groups/original and extracted hashes), `publication-20260929T0812.json`, `post-publication-20260929T0812.json` and `website-census-20260929T0812.json`. All five public edition pages returned 200 with expected counts, official URLs, historical-scope and missingness notes. Generic urllib user agent received 403; browser user agent succeeded. Originals are preserved in app-private `official-imports/census-package/` and match the package hashes. This publishes source data with CLI review history, not accepted current-boundary joins or national completeness.

The 1991 source-table bundle was installed at 08:15 UTC in 5.05 seconds, sampled peak RSS 21,832 KiB. All 237 bundle files verified; 118 workbooks / 236 worksheets are live. The 697,205 worksheet rows include definition sheets, not just data records. Verified year/area/group and district filters, two worksheets, pagination and 100 CSV row locators against preserved page evidence. Receipts: `install-1991-20260929.json`, its `.log`, and `website-1991-20260929.json` in the release directory. Originals remain preserved; the public viewer includes official workbook/catalogue references.

Updated `coverage-register.json` on server and local exports: 123 released reference entries (five Census views and 118 historical workbooks), SHA-256 `c1cf067b79567f2f5c9f47694b9d931caa8f2936a3f108cf3898c677c7739498`. Prior register preserved as `coverage-register-before-publication.json`. The reference XLSX still predates publication and needs its status labels refreshed; use the JSON and release receipts for current status.

### Latest preparation step — 29 September, 08:23 UTC heartbeat

Completed a bounded UP amenity semantic review from existing validated SQLite rows and reports. Receipt: `releases/civic-20260929/up-amenity-semantic-review-20260929.json` (server worker root; local metadata copy under exports), SHA-256 `1658dea5d81b5d2321a563e87d36b3112896d24c78a750591ac79cb024488e9a`. Preserves exact headers, cell types, source URLs/hashes and source-row witnesses. Village/town Reference Year is 2009 despite 2011 edition; other field periods need definition support. Hamlet header is row 2, not row 1. Slum/town and hamlet/village code repetition represents detail records, not automatic duplicates. Drainage/notification codes are categories; NA means Not Applicable. No indicators or current-geography joins accepted.

### Latest preparation step — 29 September, 08:38 UTC heartbeat

Built a nonpublic viewer-format compatibility fixture at `releases/civic-20260929/up-amenity-viewer-preview-v1` under the worker root, using only existing review witnesses. Six sheets preserve exact sample cells/types/source rows, reviewed header positions, original hashes/URLs and checksummed page offsets. Town headers have 429 columns; village headers have 396. All six page round trips passed; this is sample/schema verification, not full worksheet validation or a release. Receipt `up-amenity-preview-check-20260929.json`, SHA-256 `29183d5c6d15fcb7f43f300a6ae48f527a0b8fb6121c205434b48deced01b273`.

### Latest preparation step — 29 September, 08:53 UTC heartbeat

Recovered exact acquisition timestamps from preserved metadata and reconciled keys, official URLs, original hashes and metadata hashes to SQLite source registrations. Town: `2026-09-25T13:01:19.165958+00:00`; village: `2026-09-25T13:00:59.684362+00:00`. Receipt `releases/civic-20260929/up-amenity-acquisition-provenance-20260929.json`, SHA-256 `02fade9556bf282c565595d580657baad2a9601864bf0905057ecbe7f7d90337`. These packages' `extracted_sha256` fields hash acquisition-metadata JSON, NOT worksheet cells. The private preview now uses explicitly named `acquisition_metadata_sha256`, verified retrieval dates and refreshed manifest hashes. It remains a sample, not a release.

### Latest preparation step — 29 September, 09:08 UTC heartbeat

Implemented `pilot/prepare_amenity_pages.py` for streaming already-staged rows; three focused unit tests passed (typed blanks/zeros, sparse row locators, repeated parent codes, formulas/errors, visible preamble notes, missing headers and duplicate row rejection). Worker copy SHA-256 `0a16cdeca8a2c972f642472a536ddfac02e5232a6ebd5fb02e0e5309c1aa5479` matches local. No application deployment.

Built full private UP amenity pages at `/home/pollmedia/census-worker/releases/civic-20260929/up-amenity-full-v1`. Receipt `up-amenity-full-build-20260929.json` records all page checksums/counts, metadata digests, source IDs and resource use. Runtime 54.49 seconds, peak RSS 26,640 KiB. Source body rows: town 915; slums 2,015; village 106,774; hamlets 165,081; remarks 16 + 1, plus a separate visible hamlet title/notes sheet. These are source rows, not approved indicators. Totals reconcile to 2,949 and 271,860 previously validated raw rows including headers/notes. Every generated page round-trip checksum/count passed. Memory remained above floor; no swap/PSI event observed. Dataset is NOT yet published.

### Latest verification step — 29 September, 09:23 UTC heartbeat

Verified both UP packages through the deployed `HistoricalCensusTableController` and Blade views using a process-local private storage override; no live index/data/config changes. All seven worksheets rendered, including the supplementary hamlet title/notes sheet. Checked exact source-cell arrays and source-row locators against generated page evidence, plus CSV source row/official URL/worksheet fields and row counts on every first page. Wide town/village views were approximately 620 KB HTML, within bounded page handling. Receipt `releases/civic-20260929/up-amenity-deployed-reader-check-20260929.json`, SHA-256 `9e8e44cb5b2a6071d1af7474776aea3673984ae74191372c5be454ce9aa0b7f1`. Source-table controller compatibility passed; no amenity publication yet.

### UP amenity release completed — 29 September, 09:27 UTC

Implemented/tested `pilot/add_census_source_tables.py`; four tests passed for preservation/backup, stale index, collision and corrupt evidence rejection. Additive live installation succeeded in 3.30 seconds. Source index now has 120 entries, preserving the original 118 exactly. Added town `2011-1184-894e09da99a8c5c2` and village `2011-1184-4d208908dc18815c`. All seven public worksheets and CSV downloads returned 200 with expected counts; an existing 1991 source was also checked. This is original-structure source evidence, not accepted normalized indicators or current geography.

Receipts: `releases/civic-20260929/up-amenity-install-20260929.json` and `up-amenity-public-check-20260929.json`. Exact prior index backup: `index-before-up-amenities.json`, SHA-256 `e4365c871b79a3af9c26e973b564b179452e8c055db3abb57ada859b02afa0d4`; new index SHA-256 `357a3381723c32f77f683a0c801fa885fdd5342bf9ed3e641aa42d2ae60fba42`. Recovery: under the same exclusive release lock, verify current index still matches that new hash, then atomically restore the backup; retain new evidence directories unreferenced. Never overwrite later index changes. Coverage register updated on server/local to mark these two amenity references published. No app code deployment or migrations.

### Latest release — remaining amenity workbooks published pending admin review

User explicitly requested publication before admin confirmation, with uncertainty/math flags visible. Published the remaining 66 validated amenity workbooks as original-structure source evidence: 860,797 raw rows including headers/notes, not approved indicators. All original rows are retained under generic column headings where header interpretation is unconfirmed. Source titles, group filters and row notes say PENDING ADMIN REVIEW; geography, field periods and mathematical consistency are explicitly unconfirmed. No implied completed math review. All 66 public source pages returned 200 with review labels; three CSV samples retained the notes. Eight focused packager/installer tests passed.

All 68 registered amenity workbooks are now available (two UP plus this batch). The source-table index has 186 sources, preserving the original 120 entries exactly. Receipts in `releases/civic-20260929/`: `amenities-pending-admin-build.json`, `amenities-pending-admin-install.json`, `amenities-pending-admin-public-check.json`. Build: 318 seconds, peak RSS 28,660 KiB; install: 13.15 seconds. Backup `index-before-66-amenities.json`, prior hash `357a3381723c32f77f683a0c801fa885fdd5342bf9ed3e641aa42d2ae60fba42`; new live index hash `3bebef4fdb7880b798f23137bde6386e0c7089076d8148e4aced4da98fb603dc`. Recovery only restores that backup under the release lock after matching the new hash; retain unreferenced evidence directories. Coverage register updated server/local.

A brief post-install swap sample reached 23.13 MiB/sec, then zero on the next check; final 0.14 MiB/sec, 6,617.73 MiB available RAM, memory PSI 0.16. No memory-floor event. Do not change swap settings. Publication labels do not implement a dedicated admin confirmation workflow: existing source-table reader has no approval action. That UI would require scoped code work and user-managed deployment; do not claim it exists.

Exact next action: publish remaining existing PDF-derived candidate evidence with source/page locators and explicit OCR/math/geography review flags, using an appropriate existing reader if compatible. Do not invent confirmed joins or convert uncertain candidates to indicators. Maintain source-preservation and integrity checks; semantic review alone must not block pending-review source publication. Refresh stale reference XLSX. Extraction remains paused. Examelite resume commands were provided; execution still unconfirmed. Baseline Git `e79589a`.
## Continuity protocol

### Shared-load diagnosis and pending decision

Read-only diagnosis at 07:41–07:42 UTC identified genuine CPU saturation: Examelite Python/OCR used about 3.08 cores, Pollmedia PHP-FPM 1.24, MariaDB 0.65 in a four-second sample. Civic extraction is stopped. Repeated dynamic-page requests, including claimed crawler agents, contribute to Pollmedia traffic. See `civic-server-load-diagnosis-20260929.md` for evidence and limits. The user explicitly approved temporarily pausing Examelite bulk jobs for the import, allowing active jobs to finish safely and restoring them afterwards. Do not ask for that permission again.

The user supplied successful deployed Artisan output confirming four Examelite queues are now paused: `admission_database:admission-imports`, `admission_database:official-content`, `database:paper-extraction`, and `database:exam-pdfs`. Existing active jobs may finish; do not force-kill them. Pollmedia SSH remains available for read-only process/resource checks and civic work; administrative Examelite controls were executed by the user, not this account.

At 08:01:34 UTC, load had fallen to 11.91 on six CPUs, with 5,920.59 MiB available RAM, 65.65 GiB disk, zero sampled swap traffic and zero memory PSI. One Examelite Python process remained active. This was a historical sample before the user clarified the import policy; the five-source import has since succeeded. Keep both civic extraction crons paused.

Restoration obligation: after the import window, have the user run `sudo -u examelite php8.4 artisan queue:resume CONNECTION:QUEUE` from `/home/examelite/public_html` for exactly the four queues above, and verify the output. Do not resume unrelated queues or civic extraction. No further permission is needed for the already authorized temporary pause/restoration.

Every run records only: completed action and receipt, exact next action, remaining blocker and current Git commit. Update this file when state changes; keep detailed evidence in release receipts, not the automation prompt. Read chronological checkpoints only for a specific missing fact. Never restart the project or ask the user to restate agreed scope because the chat became long.

The existing 15-minute task is preparation/import-only. Routine summaries stay at three-hour intervals; notify new material failures or completed imports promptly and deduplicate unchanged resource alerts in `exports/civic-monitor-notifications.json`.

### PDF candidate release preparation — 29 September, 09:41 heartbeat

Prepared all 4,217 existing PDF candidates (Bareilly 3,134; Shahjahanpur 1,083) in worker `releases/civic-20260929/pdf-candidates-pending-admin-v2`. Original candidate objects, printed dashes, raw lines/cells, exact official PDF URLs, original hashes and page/line/text hashes remain intact. Verified both originals, all four candidate-file hashes, saved page hashes and every physical line witness; no duplicate physical locators. Separate bounded arithmetic checks found zero negative/noninteger populations or reported SC/ST bounds violations; this does NOT confirm header interpretation, visual accuracy, missing components or geography. All rows labeled PENDING ADMIN REVIEW. Three focused arithmetic tests and package round-trip checks passed.

Receipt `pdf-candidates-preparation.json`; candidate SHA-256 `ca6d0aa4bc0abeae428f3c8533e252bb6621ab4df4c4ce5a15f86c5a1613c9e8`. Preparation 0.83 seconds, peak RSS 24,484 KiB, RAM ~6.9 GiB, swap <=0.047 MiB/sec. Initial v1 attempt stopped before processing because server Python lacks hashlib.file_digest; fixed with streamed hashing. Empty v1 artifact is not a release. No new extraction, website mutation or deployment.

Exact next action: add PDF-aware source/page/line labels to the source-table reader in a scoped tested code change for user-managed deployment, then adapt/install this pending-review package and verify public PDF links/CSV. Current reader says Excel workbook/worksheet/workbook row, so publishing these PDF candidates through it unchanged would misidentify their provenance. Review flags are not the publication blocker; reader labeling is. Prepared PDF rows remain NOT LIVE. Refresh reference XLSX afterward. Extraction remains paused.

### PDF viewer code and bundle ready — next action requires user live pull

PDF-aware HTML/CSV labels implemented and tested locally: 7 feature tests / 44 assertions passed; Pint passed. Workbook behavior remains covered. Worker bundle `releases/civic-20260929/pdf-viewer-pending-admin-v1` contains 4,217 rows in bounded checksummed pages, two source IDs `2011-1185-4a15246b6203c69a` and `2011-1287-4b536b63aaa7906a`. Receipt `pdf-viewer-preparation.json`; every page round-trip hash/count passed. Original printed lines, missing components, PDF/text hashes, locators and pending-review/arithmetic notes remain visible. No live index mutation or code deployment.

Exact next action: user pulls latest main on live app (standing user-managed deployment constraint). Then verify deployed HistoricalCensusTableController and historical-census-tables Blade hashes against tested local files; install `pdf-viewer-pending-admin-v1` with existing additive installer, fresh resource check/shared admission lock and exact current-index precondition, backup to `index-before-pdf-candidates.json`. Verify both public pages and CSV page/line/PDF links, update coverage register and receipt. Do not restart extraction. No migration or asset build required for these PHP/Blade changes.

### PDF candidates live — 29 September, 09:57 UTC

User-managed server pull reached a66b362; deployed controller and LF-normalized Blade SHA-256 match tested files. Published 4,217 existing PDF candidates (Bareilly 3,134 / Shahjahanpur 1,083) as PENDING ADMIN REVIEW. Official PDF links, physical page/text-line locators, printed lines and review notes appear in HTML and CSV. First/last pages and CSV exports passed for both sources. Prior 186 source entries preserved exactly; live source index now 188. This is only the selected urban-block PDF subset, not full PDFs, approved indicators or complete historical coverage.

Receipts in worker releases/civic-20260929: pdf-candidates-install.json and pdf-candidates-public-check.json (local metadata copies in exports). Install 0.395 seconds, peak RSS 17,864 KiB; RAM >=5,902 MiB, sampled swap/PSI zero. Backup index-before-pdf-candidates.json; before hash 3bebef4fdb7880b798f23137bde6386e0c7089076d8148e4aced4da98fb603dc; after hash 4bd275f40a60a99269a652e3eebf825a6bcb206ec04c3b07528db64db3fce586. Recovery: under release lock verify current index still equals after hash, atomically restore that backup, retain evidence directories unreferenced. Never replace a later index. Coverage register's two original-PDF references now explicitly mark only candidate subsets published. Both extraction crons confirmed paused. No deployment or migration performed by this run.

Exact next action: refresh the stale civic-source-references-20260929.xlsx from the current coverage register and release receipts; preserve exact original URLs/hashes and distinguish published source evidence from approved indicators. Dedicated admin confirmation UI and public local-original download archive remain separate unfinished items. Do not reimport these PDFs or restart extraction. Base code a66b362.

### Civic frontend implementation — 29 September

Added /india/census/explore and /india/census/places/{record}, and routed shared header Census link to the explorer. Existing /india/census catalogue URLs remain intact. Published Census records now support state → district → subdistrict → village/town → ward navigation wherever those records exist, place metric tabs, source/residence controls, breadcrumbs, search, locator maps and a responsive right-hand related-areas panel. Existing Pilibhit village profiles are linked from supported district/subdistrict contexts. AC/PC links require an accepted explicit identifier plus unexpired accepted relationships; duplicate places retain all supporting URLs. Currently the explicit district crosswalk adapter supports UP Census 2011; unlinked places have an honest empty state. Cross-year place continuity is NOT inferred from names/codes: year changes start at the selected year's state list. Raw source tables remain accessible separately, without unverified place joins.

Verification: 17 civic/catalogue/source-viewer tests, 133 assertions passed; Pint and diff checks passed. Browser checked actual local published data through Uttar Pradesh → Pilibhit, including map, three subdistricts, existing village link and sourced AC sidebar; desktop right column/mobile stacked layout with no horizontal overflow. Broader ElectionGeographyFrontendTest has one pre-existing missing 'Assembly archive' label expectation, reproduced with unchanged baseline header; election implementation was not modified. Local preview remains at http://127.0.0.1:8769/india/census/explore. No live code deployment, migrations or extraction.

Next: user-managed pull of the frontend commit; verify live Census header navigation and a district profile. Continue source-reference workbook refresh separately. This establishes the expandable frontend; it does not mean all raw evidence is normalized into place profiles or that cross-year joins are confirmed.
