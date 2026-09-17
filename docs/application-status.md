# Integrated application status

Implemented on 15 September 2026 in `D:/pollmedia/application`.

## Review locally

- District: http://127.0.0.1:8000/india/district/pilibhit
- Parliamentary constituency: http://127.0.0.1:8000/india/pc/pilibhit
- SIR: http://127.0.0.1:8000/india/sir

The Laravel application replaces the need for the Python server when reviewing these pages. The original Python extraction scripts remain available for source research.

## Working

- Clean Laravel 13 installation with locked Composer dependencies.
- Thirteen shared domain tables for provenance, geography, governance, people/profiles and development observations, plus Laravel's standard user/cache/queue tables.
- Separate district and PC views; district demographics are never copied to the PC view.
- Census 2011 population, household and literate-population counts with source references; searchable 20-village sample on the district page.
- Official directory snapshots for an MP, four MLAs, district magistrate and chief development officer. Cards show last verification date; no tenure dates were inferred.
- Verified external profile links for Jitin Prasada, including Digital Sansad and Wikipedia. Other cards use their official directory links until person-specific biographies are verified.
- Reviewed officeholder replacement service: supersede old observations, retain unknown effective dates, preserve history and reject unsupported acting/conflicting cases. It does not discover or approve external changes automatically.
- SIR aggregate sample served through Laravel API routes with filters, search and pagination.
- Interactive MapLibre map below the place title: source village layer, street map, search, selection details, zoom, reset and responsive expanded view. All 1,446 SOI source geometries passed validity and regional extent checks after projection conversion. This does not establish current administrative/electoral boundaries or Census joins.
- Integrated sections for development, elections, citizen issues, geography and surveys; unavailable data and workflows are explicitly marked pending.

## Validation

SQLite migrations and seed completed successfully. Five tests passed with 28 assertions, including district/PC separation, seed idempotency, SIR scope/validation, and a synthetic officer replacement updating the page while preserving the earlier record. Test identities exist only in the in-memory test database.

## Run

From `D:/pollmedia/application`:

```text
php artisan migrate
php artisan db:seed --class=PilibhitSeeder
php artisan test
php artisan serve --host=127.0.0.1 --port=8000
```

The seeder is for the checked-in research snapshot, not a general production importer. It does not restore an old superseded officeholder. Database fixtures contain aggregate source data, not individual electoral records.

Composer's optimized autoload generation stalled on this Windows machine. Standard development autoload generation succeeded; `optimize-autoloader` is false for now. Test optimized builds again before production.

## Still pending

PostgreSQL/PostGIS and PHP's PostgreSQL driver were not available through the checked local tools; SQLite runs the current application. PostGIS geometry migration, current LGD joins, scheduled imports, automated officer refresh and production release activation remain pending. The production database constraints and planned domain tables are in the [database design](database-design.md).

The page is currently server-rendered Blade; React/Inertia is not configured. Election coverage before 2019, AC election results, water/education/health/employment imports, issue submission/moderation, notifications, surveys and individual SIR tables/search are not active. The map displays SOI source village shapes; PC/AC boundaries and thematic overlays remain pending. No public deployment has taken place, and source-permission review remains open.

## Map build and verification

Run `python pilot/build_map.py` from the workspace root after acquiring the official ZIP (Python dependencies: pyshp 3.1.6, pyproj 3.7.2, shapely). The script verifies the source hash, rejects invalid shapes without repair, simplifies by 15 metres in the original projection, converts to EPSG:4326, and records checks in `pilot/data/map-validation.json`. GeoJSON is stored locally at `application/storage/app/maps/pilibhit-villages.geojson` and served through a fixed API route. No individual electoral data are included.

MapLibre GL JS 5.6.0 and its license are vendored for this pilot. OpenStreetMap street tiles require connectivity; attribution remains visible, browser caching is retained, and there is no tile prefetch or offline download feature. Source village search still works if WebGL is unavailable. A production tile provider and source publication review remain launch tasks.

Browser checks covered village search/selection, rendered shapes, expansion and a 390-pixel mobile layout. The five existing application tests passed with 28 assertions. Laravel Boost was installed as required by application bootstrap instructions.

## Election page: 2019 and 2024

Political/election information now precedes authority and development sections. Pilibhit PC has a server-rendered year selector, all candidate/NOTA rows, vote-share bars, historical winning margins and official report links with page locators. District pages link to the PC results without presenting them as district totals.

Two tables (`election_contests`, `election_candidate_results`) preserve election-time party labels and source releases. The fixture seeder validates general/postal sums, unique rows and constituency totals before a transaction. Repeated seeds do not duplicate records or reactivate superseded editions; subsequent releases require deliberate activation. This is still a research snapshot importer, not scheduled synchronization.

Sources downloaded through the official ECI UI after user approval of its research/statutory-forms disclaimer:
- 2024: report 33, page 434, all 11 rows; 1,833,961 electors; 1,161,235 all votes polled; 1,154,206 valid candidate votes; 6,741 NOTA. Candidate + NOTA = 1,160,947, leaving 288 outside those rows.
- 2019: report 33, page 459, all 14 rows; report 32, page 773 for totals. 1,761,207 electors; all ballots = 1,182,890 EVM + 4,335 postal = 1,187,225. Candidate + NOTA = 1,186,589; valid candidate votes = 1,176,616. The 636 difference is not added to candidate votes.

Vote share and margin percentage points use **all votes polled** as denominator. The separate card is explicitly called **recorded-vote participation** and uses candidate + NOTA totals / electors; it is not substituted for an independently sourced polling-day turnout figure. Thus the verified 2024 BJP share is 52.29% on this report basis, different from some election-night pages. No booth/village or demographic voting patterns are inferred. Raw PDF copies and hashes are retained under `pilot/raw/elections` and in the fixture provenance; the original statutory forms remain final.

Seven tests / 46 assertions passed, covering arithmetic, geography separation, year selection, invalid totals and seed idempotence. Browser review verified both years, chart rendering and section order. A Windows compiled-view lock during simultaneous tests/browser rendering was cleared with `php artisan view:clear` and the page then rendered successfully. The application is not a Git checkout, so Pint's required dirty mode was attempted and then replaced by explicit changed-file formatting.

Rebuild this slice with `php artisan migrate` followed by `php artisan db:seed --class=PilibhitElectionSeeder` after the core Pilibhit seed. Current-party membership history, older election years, AC/village templates, automated reports and bulk AI SEO remain follow-up work.
