# Pollmedia Pilibhit data pilot

This is a bounded proof within the [full Pollmedia platform plan](../docs/platform-roadmap.md). The project includes development cards, maps, elections, citizen issues, authority responses and surveys as well as SIR.

Local prototype: http://127.0.0.1:8765/india/sir

## What is working

- An official SIR enumeration PDF, Census workbook and Survey of India boundary archive were downloaded with SHA-256 hashes and source URLs.
- The SIR sample extracts 1,784 listed records from the first 20 complete parts of AC 127 into **aggregate part summaries**. The source has 1,881 pages and was generated on 17 December 2025. It is not a final voter roll; its counts are not electorate totals or confirmed deletions.
- State/PC/AC selectors, station/part search, pagination, no-data states and official links work through a local API. Only AC 127 contains imported sample data. Individual search currently links to ECI.
- A separate 20-village Census 2011 sample is displayed. It is not automatically linked to polling parts.
- The boundary attributes contain 1,446 Pilibhit records. Comparing village/town codes gives 1,444 unique matches to Census, two unmatched codes and no ambiguous matches. Of the matches, 375 have different subdistrict codes. This is a candidate crosswalk, not proof of current LGD status or boundary correctness.
- Repeatable source refresh and atomic, versioned SQLite imports work. SQLite is used only for this proof; the application design remains PostgreSQL/PostGIS.

## Verification on 15 September 2026

- Official Census and SIR sources were fetched twice. Both hashes were unchanged; the second import returned `unchanged` with no duplicate release.
- Three automated tests passed: identical re-import, changed-release replacement with history, invalid/duplicate-release rejection preserving the accepted version. Changed releases are **synthetic test fixtures in temporary databases**, not edits to official source data.
- HTTP checks passed for filtering, both pages, empty selection, a constituency with no imported data, and station search.
- Browser checks confirmed rendering, constituency switching and pagination. PDF page 1 was rendered and visually inspected; sampled part row sequences were checked for continuity.
- One identifier used backslashes, causing the first parser to skip a row. Parsing was corrected to use the row-number columns, and all sequences then reconciled.
- One category on source page 45, row 22 is spelled `Prmanently Shifted`. An explicit parser mapping normalizes it to `permanently shifted`. All 1,784 sampled rows classify; exact source wording remains in the PDF.
- The first boundary download was truncated. Standard HTTP range downloads recovered the full 95,532,790-byte archive; ZIP integrity passed. The truncated hash is retained in the manifest for audit and is not used in the crosswalk.

## Run locally

Requires Python with `openpyxl` and `pypdfium2`. In this workspace the bundled Python executable is:

`C:\Users\menha\.cache\codex-runtimes\codex-primary-runtime\dependencies\python\python.exe`

From the project root, with that executable:

```text
python pilot/sync.py
python pilot/inspect_geography.py
python pilot/test_store.py
python pilot/server.py
```

`sync.py` refreshes the two small data sources, extracts the bounded sample and activates only validated data. A running server reads the accepted version on each request; refreshing the page reflects an accepted update. No scheduled job is installed. The boundary archive is acquired separately with `recover_boundary.py` when necessary. Its cached byte ranges must be kept with the matching source version; re-acquisition logic should be hardened before production use.

The server binds to loopback only and exposes aggregates and the HTML page. It does not serve raw PDFs, workbooks, boundary files, arbitrary workspace paths or personal voter records.

## Outstanding work before a full pilot or public launch

1. Current LGD export/API onboarding: the direct download and PC/AC mapping pages require CAPTCHA; no bypass attempted. Resolve two unmatched Census/boundary identifiers and validate historical changes.
2. Actual polygon validation and AC/PC spatial joins. The SOI projection is Lambert Conformal Conic in metres, not latitude/longitude; transform correctly before web mapping.
3. Obtain a final-roll sample through an available official route. The direct ECI download page returned an access error in web retrieval. Do not substitute enumeration material for final status.
4. Public republication permissions: [Pilibhit website policy](https://pilibhit.nic.in/website-policies/) requires permission and attribution; [SOI copyright policy](https://surveyofindia.gov.in/pages/copyright-policy) requires written permission. Review applicable dataset-specific terms. No permission request has been sent and nothing has been deployed publicly.
5. Individual-table/search implementation after verifying the source access mode and permitted fields. The prototype validates aggregates only.
6. Broader PDF layouts, historical revisions, publisher link discovery, scheduled workers, alerts and source-specific operational limits. The current refresh targets known URLs; it cannot discover a release moved to a new URL.

## Files

- `acquisition.json`: source manifest and hashes.
- `data/sample.json`: accepted extraction candidate (aggregate data only).
- `data/crosswalk.json`: candidate code correspondences and mismatches.
- `data/geography-inspection.json`: DBF schema and projection.
- `data/pilot.sqlite`: accepted versions.
- `raw/`: original source files, retained locally.
- `web/index.html`: pilot interface.

Raw source files, personal-data screenshots, local databases and temporary downloads should not be committed or deployed.
