# Existing civic data release — 29 September 2026

## Authorization and current state

The user clarified that only extraction and preparation of **new** data should pause. Preparation and website import of already collected civic data should continue. Keep both extraction cron lines commented `PAUSED_BY_USER_20260929`. The 15-minute heartbeat now handles existing-data preparation/import only. Do not acquire new sources, run OCR, register catalogues or touch election pipelines. Resume collection only after reporting successful completion of the existing-data release, not after a partial import.

No live data import has occurred in this release. The live `census_editions` and `census_publications` tables were empty at the read-only preflight. The historical source-table directory was absent. Server load remained approximately 18–21 on six CPUs during preparation, so live import was held. This is a resource wait, not an import failure.

The existing application already has both required readers/import paths. No code deployment or migration is needed for the first two subsets below. The user still handles live code pulls if later changes become necessary.

## Inventory and reference documents

Server metadata snapshot: `/home/pollmedia/census-worker/releases/civic-20260929/source-inventory.json`.

SHA-256: `eca348352a8f84a1b3ee7f4a87febab298983155937623cc9ad23515da17eb35`.

Local reference workbook: `exports/civic-release-20260929/civic-source-references-20260929.xlsx`. Tabs contain release status, five Census package references, 118 historical workbook references, 68 amenity validations and 378 PDF evidence manifests. PDF manifest count is not a deduplicated publication count. Exact official workbook/PDF URLs and hashes are included. The JSON retains original metadata and full timestamps. Bulk originals and row data remain on the server.

The 07:38 UTC heartbeat added `coverage-register.json` in the same local and server release directories. This initial 570-entry register adds the official UP administrative LGD export, distinguishes collection-page provenance from exact-download URLs, records validation/publication states, and marks data.gov.in and unestablished departmental coverage as gaps. It is not exhaustive historical coverage. `lgd-administrative-reference.json` preserves the source metadata and existing crosswalk-report checksum/counts. No original-cell extraction or crosswalk acceptance was repeated. Server load remained above admission, so no live import ran.

The 07:53 UTC heartbeat prepared `lgd-name-review-queue.json` from the existing diagnostic and candidate files, preserving all 34 unresolved name differences on 15 PDF pages with worksheet/page/line locators. Its SHA-256 is `81b7da19f220715b907dce7988e899d4114c8db2df52d84c19ee201d9ddcbbbd`; both input hashes were checked against the saved diagnostic hashes. No join was accepted and no raw extraction was rerun. First review target: the three page-83 exceptions, including the potentially meaningful Ehatmali suffix. Load remained 19.43 on six CPUs; live import still waits for admission.

| Subset | Preserved evidence | Website path and remaining review |
| --- | --- | --- |
| 2001/2011 Census | Five source views, three distinct original workbooks, 30,525 filtered rows | Existing Census catalogue importer. Live `--check` passed. Import as drafts, inspect counts/flags/geography/period and then publish reviewed editions. |
| 1991 Census | 118 sources, 236 worksheets, 697,205 data rows | Existing historical source-table viewer, retaining definitions, original columns and official references. Original-cell validation is complete; do not repeat it. Geographic joins and normalized indicators remain unapproved. |
| Amenities | 68 verified raw-cell workbook reports | Reference-year distributions and identifier diagnostics preserved. Review codebooks, dates and geography before mapping indicators. Workbook edition 2011 does not make every observation a 2011 value. |
| PDF/OCR | 378 PDF evidence manifests; previous checkpoint records 4,217 unverified structured rows | Source evidence only. Table interpretation, OCR uncertainty, geography and period remain review work. |

Do not sum overlapping geographic levels, Total/Rural/Urban views or SC/ST subsets with general population. No national completeness or current-service claim follows from this inventory. The original project scope remains in `platform-roadmap.md`, `database-design.md`, `data-source-feasibility.md`, `sync-and-sir-spec.md` and `civic-server-work-plan-20260925.md`.

## Tested first release paths

Application root: `/home/pollmedia/app/application`. Isolated preparation root: `/home/pollmedia/census-worker`.

### Five-source Census package

Package: `/home/pollmedia/census-worker/packages/pollmedia-census-2001-2011-20260918.zip`.

SHA-256: `7d9c59d79880b8a0aa551c3398c63a986913ecda4ae7d3f21e93fe57fd9d0170`.

The deployed `census:import-package` accepts the package and verifies all five source/extraction checksums and configured scopes. Its `--check` does not perform full row normalization. The transaction on actual import runs the row-level catalogue validator and rolls database changes back on invalid rows. Originals written before a failure may remain as harmless content-addressed files; preserve them.

When resources permit, capture a fresh scoped pre-import snapshot and receipt, then run the existing command without `--publish` first. Inspect all resulting draft counts and discrepancy notes. Publish only after that review, using the existing command/service and expected-current publication checks. Reruns reuse identical editions. Never overwrite a different existing publication automatically.

```sh
php artisan census:import-package /home/pollmedia/census-worker/packages/pollmedia-census-2001-2011-20260918.zip --sha256=7d9c59d79880b8a0aa551c3398c63a986913ecda4ae7d3f21e93fe57fd9d0170 --no-interaction
```

Public endpoint after successful publication: `https://pollmedia.org/india/census`. Verify edition selection, source links, historical geography, blank/zero distinction and discrepancy notes. Save imported edition/run IDs and publication pointers in a release receipt. Recovery withdraws only newly published editions using the existing catalogue withdrawal path, preserving originals and review history; do not delete unrelated records or restore the entire site database.

### 1991 source-table bundle

Package: `/home/pollmedia/tmp/census-1991-20260925/pollmedia-census-public-tables-20260918.zip`.

SHA-256: `1ca3fd675cd153e75089137c60736a59e041c9cfb7fea4f0a7537afbe296c16c`.

The tested installer is already staged at `/home/pollmedia/tmp/census-1991-20260925/install_census_source_bundle.py`; it is also versioned as `pilot/install_census_source_bundle.py`. Compare its checksum to the tested version before use. It verifies bundle members and installs atomically, refusing to replace an existing directory. This is release integrity verification, not a rerun of completed raw-cell validation.

Destination: `/home/pollmedia/app/application/storage/app/private/census-source-tables`. Recheck absence before installing. If it now exists, inspect it and stop replacement until reconciled. Capture this pre-state in the receipt. Verify the deployed reader, storage root and permissions before installation.

Public endpoint: `https://pollmedia.org/india/census/source-tables`. Verify year/area/group filters, at least two worksheets, pagination, source definition links and CSV row locators. For recovery of a newly installed directory, move that exact directory to a private release quarantine after checking its resolved path and receipt identity; never remove originals or staging databases.

## Validation and next run

On 29 September, one sequential local PHPUnit run passed all 15 tests / 97 assertions across `CensusPackageTest`, `CensusCatalogueTest` and `HistoricalCensusTableTest`. The bundle installer passed its two tests. Earlier overlapping test invocations interfered with shared fake storage; the sequential run resolved those test-only failures. No application code changes were needed.

Before substantial server preparation/import require available RAM >=3 GiB, one-minute load <= CPU count, swap traffic <=8 MiB/sec, memory PSI some avg10 <=5%, and disk reserve >=10 GiB. Use low priority, a 1.5 GiB address-space cap and the 1.5 GiB ongoing RAM floor. Do not compete with workers or other import attempts. Do not relax admission for this release. Capture fresh scoped backup/receipts before writes; checks and tests alone do not establish successful publication.

Next run: check resources; continue reference/semantic review of existing evidence while waiting; execute the first eligible import path once admitted, verify the website, update this document and the reference workbook with actual receipts. Keep remaining raw-only subsets explicitly pending. Do not label the whole task complete after these first two subsets.

## Release-code preflight completed

The 07:23 UTC heartbeat on 29 September verified these server files exactly match the tested local files:

| File | SHA-256 |
| --- | --- |
| Staged `install_census_source_bundle.py` | `f67e08e32587083427431fa8a710136f159e3345cfd0a32e202e938903987c14` |
| Deployed `HistoricalCensusTableController.php` | `f7a49795f89c02fdc207c967e4c12824c40900dc6755fb7ddd859a75ae4794cf` |
| Deployed `ImportCensusPackage.php` | `478bf6937d7a1061b15c965670fee4d5b6fbc6388904d22dad3215c4e960fc67` |

Receipt: `/home/pollmedia/census-worker/releases/civic-20260929/release-code-preflight-20260929.json`. The historical destination remained absent and its parent writable. This verifies release-code identity, not complete deployed dependency equivalence or completed data import. Fresh storage configuration/pre-state and resource admission are still required at execution time. Load was 15.59 on six CPUs, so no import was launched.
