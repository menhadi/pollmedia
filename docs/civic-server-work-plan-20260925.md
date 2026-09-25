# Historical and civic data preparation on the server

The user confirmed on 25 September that this task should cover the original Pollmedia civic-data scope, including historical Census, LGD village/administrative identities, education, health and other development indicators. This extends the census-only monitoring scope. The guiding documents remain `platform-roadmap.md`, `data-source-feasibility.md`, `database-design.md` and `sync-and-sir-spec.md`; their old implementation-status statements must be checked against current code and receipts.

## Work order and acceptance evidence

| Priority | Dataset family | Work and release condition |
| --- | --- | --- |
| 1 | Census and historical geography | Preserve available editions, PCA, decadal tables, DCHB and village/town directories. Validate original cells, headers and identifiers. Map indicators only with definitions, reference year, units and source notes. OCR only scanned pages that need it. |
| 2 | LGD and village links | Extract preserved administrative/electoral exports; collect supported new releases state by state. Preserve village, GP, block, subdistrict, district and state codes as strings. Keep Census/LGD codes, boundary editions and effective dates distinct. Unmatched, split and merged places remain explicit review findings. |
| 3 | Historical amenities | DCHB village/town amenities provide dated education, health, water, transport and other facility evidence. Establish exact release URLs, fields and geography before extraction. Historical availability must not be presented as current service quality. |
| 4 | Education | Discover official UDISE+ reports and accessible aggregate releases by academic year. Preserve school identifiers and reporting geography. Detailed access may require the publisher's data-sharing process; no student personal data is needed. |
| 5 | Health | Discover official HMIS aggregates, facility inventories and published survey tables. Keep facility activity, resident population measures and survey estimates separate. Preserve denominators, periods and geographic scope. No patient personal data is needed. |
| 6 | Water, infrastructure and livelihoods | Use the roadmap's JJM, infrastructure and employment sources where access and definitions support extraction. Record observation date, denominator and reporting geography; do not infer service quality from administrative counts. |
| 7 | Live-ready release packages | Produce checksummed, versioned data-only packages plus compatible tested importers, validation summaries, coverage/gap inventory and rollback/recovery instructions. A raw staging database alone is not ready for live publication. |

Election work already running elsewhere remains independent. This work should reuse verified identifiers and source evidence without starting duplicate election jobs. Citizen reports and surveys are future platform inputs; official datasets must not be fabricated to fill those modules.

## Server execution and resource policy

The current isolated worker is `/home/pollmedia/census-worker/`, with its own virtual environment and SQLite review database. It has no live DB credentials or web-application bootstrap. The pollmedia user's five-minute cron, per-worker lock, checkpoints, low priority, 1.5 GiB address-space cap and resource gates support unattended resumption. On this check the shared server had about 4.7 GiB available RAM and 102 GiB free disk; capacity is variable. Start with one streaming worker; introduce a second disjoint queue only after measuring workload and web/DB headroom. Retain at least 10 GiB free disk. Do not increase concurrency merely because nominal installed RAM looks sufficient.

The initial 1991 validation completed: all 118 source records and 697,205 review data rows matched the original XLSX cells. The 2001/2011 package contains five references, three distinct originals and 30,624 raw rows including headers/notes. Those counts use different row definitions and must not be added as normalized observations.

The next package uses the preserved UP administrative LGD ZIP and Pilibhit electoral XLSX. The administrative ZIP has three SpreadsheetML exports. The server parser preserves row/column indices, sparse cells, source cell types and merge/formula metadata rather than casting codes to numbers. Existing Pilibhit extraction JSON remains provenance evidence; reading all UP raw rows does not establish that statewide joins are verified.

## Access findings and alerts

The official LGD download page checked on 25 September requires CAPTCHA. Use the already preserved official exports now; record assisted acquisition or approved API access for additional releases, without bypassing the challenge. Census catalogue 1273 failed in the web reader with a 502 and the HMIS landing page returned an error on this check; these are observations about access attempts, not proof that the datasets are permanently unavailable. The earlier source matrix records conditional bulk access for UDISE+ and HMIS. Try supported official alternatives and preserve failure evidence.

User requested a progress summary every three hours and earlier notification of problems. Monitor compact state more frequently, but keep healthy interim checks quiet. Summaries distinguish completed extraction, source validation, normalization, packaged-for-review and released-to-live; report an empty queue honestly and continue the next supported priority. Flag failed jobs, checksum mismatches, resource blocks and required assisted access promptly without repeating unchanged alerts.

## Git and live release

Commit and push only reviewed collector/parser/importer code, tests and scope/checkpoint documentation. Keep originals, staging databases, bulk exports and credentials outside Git. The user controls live code pulls and schema migrations. A later pull delivers code; data moves only through a separately verified compatible import with backups and reviewed activation. Do not write to live PostgreSQL or deploy the application from this worker.
