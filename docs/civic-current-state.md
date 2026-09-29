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
| Five 2001/2011 Census views, 30,525 rows | Package integrity and deployed importer compatibility passed. Full row review/import/publication pending. |
| 118 Census 1991 sources, 697,205 data rows | Original-cell validation completed. Source-table viewer exists; bundle not installed at last check. Historical joins/indicators remain unapproved. |
| 68 amenity workbook validations | Original cells verified; reference years, field definitions and geography need interpretation. |
| 378 PDF evidence manifests | Not a unique-publication count. OCR/text is evidence, not accepted indicator data. 4,217 structured candidate rows remain unverified. |
| Official UP LGD export | Preserved source `https://lgdirectory.gov.in/downloadDirectory.do`, SHA-256 `3d8f2ad8754decb16e7b2f19512cf0526da186f2c6b4454271be2c10fb5e031e`. |
| Pilibhit code comparison | 879 explicit historical code pairs corroborated by LGD; 34 names require review. Diagnostic only, not accepted joins or unchanged-boundary proof. |
| data.gov.in | In original source plan. Zero matching source references in this civic staging database at the 29 September audit. Do not claim it was fully collected. |

Raw staging has 1,497,683 worksheet rows including headers, notes and overlapping records. Do not add this to filtered/1991 counts as a unique final total. No final approved national row count exists. Historical and geographic coverage are incomplete. No new release data had reached the live catalogue/source-table viewer at the latest read-only check.

## Next deliverable, in order

1. Complete the first existing-data release described in `civic-existing-data-release-20260929.md`. Record scoped pre-state/backup, exact package hash, imported IDs/counts, flags, source references, website checks and recovery path. Keep source-table publication explicitly distinct from reviewed place indicators.
2. Maintain one coverage register by source, source-era geography, year, indicator/table, preserved original, validation state and publication state. Include LGD references: the first reference workbook was Census-focused and is not a complete civic-source inventory. Mark data.gov.in and unsupported sectors as gaps.
3. Review existing Census-to-LGD mappings and amenity field/date definitions. Publish only supported joins/indicators; keep unmatched and ambiguous records visible. Apply the pilot method district by district using already collected evidence, without restarting collection or limiting the final scope to Pilibhit.
4. Report the usable release and unresolved gaps. Only then plan the next collection batch against those gaps, including supported LGD/data.gov.in and department sources when collection resumes.

## Blockers and safeguards

Last server samples: load approximately 18–21 on six CPUs. Live import held for resource admission. RAM, disk and sampled swap/memory pressure were within limits. Recheck; do not relax the gate or alter unrelated services. Before substantial server processing/import require >=3 GiB available RAM, load <=CPU count, swap <=8 MiB/sec, memory PSI some avg10 <=5%, disk >=10 GiB; use low priority, 1.5 GiB address-space cap and ongoing RAM floor >=1.5 GiB.

Fifteen relevant application tests and two bundle installer tests passed. Do not rerun completed raw-cell/OCR work. Narrow retesting to changed behavior or a concrete unresolved failure. No live import receipt exists yet.

### Latest concrete step — 29 September, 07:38 UTC heartbeat

Completed the initial machine-readable coverage register: `/home/pollmedia/census-worker/releases/civic-20260929/coverage-register.json` (local metadata copy in `exports/civic-release-20260929/`). It has 570 reference entries: five Census views, 118 historical sources, 68 amenity reports, 378 PDF manifests and one UP administrative LGD export. Separate source periods, geography-as-recorded, row measures, validation/publication states and remaining review are retained. This register is not an exhaustive inventory of all holdings or national coverage. The data.gov.in staging-reference count remains zero. Counts reconcile to 30,525 filtered Census rows and 697,205 historical source rows; they are not summed with raw worksheet measures.

LGD provenance receipt: `releases/civic-20260929/lgd-administrative-reference.json` under the worker root. The source URL is a collection page, checked 16 September; no exact-download URL or effective date is inferred. Diagnostic totals remain 879 matching code pairs, 845 exact names and 34 names for review. Earlier release-code preflight is preserved in `release-code-preflight-20260929.json`. No live import occurred. Latest resources: load 20.82/6 CPUs, RAM 5,833.12 MiB, disk 65.68 GiB, swap/PSI zero. Unchanged CPU alert suppressed. Next: once admitted, capture scoped pre-state and import the five-source package as drafts, then inspect rows/flags. While waiting, review existing metadata for the 34 LGD name exceptions and identify the necessary original-document locators without accepting joins. Baseline commit before this step: `569c58c`.

## Continuity protocol

### Shared-load diagnosis and pending decision

Read-only diagnosis at 07:41–07:42 UTC identified genuine CPU saturation: Examelite Python/OCR used about 3.08 cores, Pollmedia PHP-FPM 1.24, MariaDB 0.65 in a four-second sample. Civic extraction is stopped. Repeated dynamic-page requests, including claimed crawler agents, contribute to Pollmedia traffic. See `civic-server-load-diagnosis-20260929.md` for evidence and limits. The user was asked whether to temporarily pause separate Examelite bulk jobs safely for an import window and restore them afterwards. Do not modify Examelite without that answer; do not lower the civic gate. Continue lightweight review while waiting, or execute the approved pause/import path after verifying supported controls. No server changes were made by diagnosis.

Every run records only: completed action and receipt, exact next action, remaining blocker and current Git commit. Update this file when state changes; keep detailed evidence in release receipts, not the automation prompt. Read chronological checkpoints only for a specific missing fact. Never restart the project or ask the user to restate agreed scope because the chat became long.

The existing 15-minute task is preparation/import-only. Routine summaries stay at three-hour intervals; notify new material failures or completed imports promptly and deduplicate unchanged resource alerts in `exports/civic-monitor-notifications.json`.
