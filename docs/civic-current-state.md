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

Exact next action: recover original acquisition timestamps from existing source receipts, then build full streamed pages from SQLite with visible source-note sheets preserving the hamlet title row. The deployed viewer ignores extra `preamble_rows` metadata, so that metadata alone is insufficient. Additive installation must preserve all 1991 sources, back up/check the existing index, and atomically switch the index after new directories are verified. The current installer refuses an occupied destination and must not be used to replace it. No code deployment or new extraction. Resource sample: 5,768.46 MiB RAM, load 8.51/6 CPUs, 65.25 GiB disk, zero swap/PSI. Both civic cron pause markers confirmed. No new import or material failure this run; routine summary not due. Baseline Git `ad88398`.
Both prepared Census packages remain live; remaining amenities/PDF candidates/LGD joins require review. Extraction stays paused. Four Examelite resume commands were sent to the user after the import window; execution is not yet confirmed. Do not assume restoration.

## Continuity protocol

### Shared-load diagnosis and pending decision

Read-only diagnosis at 07:41–07:42 UTC identified genuine CPU saturation: Examelite Python/OCR used about 3.08 cores, Pollmedia PHP-FPM 1.24, MariaDB 0.65 in a four-second sample. Civic extraction is stopped. Repeated dynamic-page requests, including claimed crawler agents, contribute to Pollmedia traffic. See `civic-server-load-diagnosis-20260929.md` for evidence and limits. The user explicitly approved temporarily pausing Examelite bulk jobs for the import, allowing active jobs to finish safely and restoring them afterwards. Do not ask for that permission again.

The user supplied successful deployed Artisan output confirming four Examelite queues are now paused: `admission_database:admission-imports`, `admission_database:official-content`, `database:paper-extraction`, and `database:exam-pdfs`. Existing active jobs may finish; do not force-kill them. Pollmedia SSH remains available for read-only process/resource checks and civic work; administrative Examelite controls were executed by the user, not this account.

At 08:01:34 UTC, load had fallen to 11.91 on six CPUs, with 5,920.59 MiB available RAM, 65.65 GiB disk, zero sampled swap traffic and zero memory PSI. One Examelite Python process remained active. This was a historical sample before the user clarified the import policy; the five-source import has since succeeded. Keep both civic extraction crons paused.

Restoration obligation: after the import window, have the user run `sudo -u examelite php8.4 artisan queue:resume CONNECTION:QUEUE` from `/home/examelite/public_html` for exactly the four queues above, and verify the output. Do not resume unrelated queues or civic extraction. No further permission is needed for the already authorized temporary pause/restoration.

Every run records only: completed action and receipt, exact next action, remaining blocker and current Git commit. Update this file when state changes; keep detailed evidence in release receipts, not the automation prompt. Read chronological checkpoints only for a specific missing fact. Never restart the project or ask the user to restate agreed scope because the chat became long.

The existing 15-minute task is preparation/import-only. Routine summaries stay at three-hour intervals; notify new material failures or completed imports promptly and deduplicate unchanged resource alerts in `exports/civic-monitor-notifications.json`.
