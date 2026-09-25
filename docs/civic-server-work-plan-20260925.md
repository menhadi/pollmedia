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

## Acceleration authorized and enabled on 25 September

This supersedes the initial single-worker configuration above. The primary worker now reads an explicit official catalogue registry, preserves catalogue HTML and metadata, downloads at most four PDFs per pass with TLS verification and size/time limits, extracts page text, and automatically queues textless pages in batches of at most 25. Catalogue/download failures retry after 30 minutes without blocking other supported sources. Registering additional supported sources feeds subsequent cron passes; this is not unrestricted national crawling or a claim of complete coverage.

A second five-minute cron runs `/home/pollmedia/census-worker/ocr-worker/`. It has its own exclusive lock, SQLite job database, status and log. Package originals and evidence storage are shared; the primary owns text/workbook outputs and the secondary owns OCR outputs. Do not enqueue new OCR jobs in the primary queue. Existing completed primary OCR jobs remain preserved. Each worker has a 1.5 GiB address-space cap, low priority and a 4.5 GiB available-memory gate; both preserve at least 10 GiB disk. These conservative gates reserve headroom for the live website even if both start together. Page receipts and completed jobs prevent repeated extraction. Do not add further workers without measurements.

The Census site omitted an intermediate certificate required by the server's TLS client. The isolated worker now loads the emSign SSL CA G1 intermediate obtained over verified HTTPS from `https://repository.emsign.com/certs/emSignSSLCAG1.crt`; `openssl verify` validated it against the server trust store. Normal certificate-chain and hostname checks remain enabled. No system trust-store change or TLS bypass was made.

Monitor both worker statuses and `feeder-status.json`, including pending catalogue/download counts and per-source errors. An idle text worker while OCR continues is normal. Source-specific table adapters and geography/period review still follow raw extraction; flags do not block unrelated collections. No live import or publication is part of this pipeline.

## Adaptive capacity policy (supersedes fixed 4.5 GiB gates)

On 25 September the user authorized using available capacity more flexibly, with swap as backup. A shared, lock-protected admission ledger now permits one civic worker when available RAM is at least 3 GiB, and a second only at 4.5 GiB. At most two workers may be admitted. The ledger records PID and process start time, removes dead leases and releases admission on normal exit. Each worker retains its separate job lock and 1.5 GiB address-space cap. Once admitted, the existing between-unit checks preserve 1.5 GiB available RAM and 10 GiB disk; those are continuation floors, not admission thresholds.

New runs also wait if the one-minute system load exceeds logical CPU count, measured swap-in plus swap-out exceeds 8 MiB/second, or Linux memory pressure `some avg10` exceeds 5%. Swap usage alone does not block work; active swapping/pressure does. The sample covers 0.25 seconds and is an admission-time check, not continuous system telemetry. No swap allocation, kernel swappiness or live service configuration changed.

Nine resource/worker tests passed. The shared policy was installed with both worker locks held. A real server admission check correctly rejected a start at load 10.79 on six CPUs despite near-zero measured swap traffic, and left no stale lease. Available RAM was 4,480 MiB. Both existing civic queues had completed before this policy change; future supported work resumes through the existing cron when all admission conditions pass.
