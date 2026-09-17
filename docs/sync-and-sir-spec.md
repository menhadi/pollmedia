# Pollmedia: source synchronization and SIR

Date: 15 September 2026. Status: requirements with a [bounded local prototype](../pilot/README.md). Aggregate tables and on-demand source refresh are implemented; individual tables/search, scheduled synchronization and public deployment are pending.

## 1. Automatic updates from official sources

Excel and PDF are source formats, not a requirement for manual maintenance. Pollmedia should periodically discover new releases, download changed files, validate them and refresh the affected pages. A documented official API is preferred where it provides the required data and permits this use.

```text
Official API / Excel / CSV / PDF
  → scheduled source check
  → changed release detected
  → download and preserve version
  → parse and validate
  → publish accepted version
  → refresh related pages and caches
```

Updates are eventual: Pollmedia can reflect a change after the publisher exposes it and a successful sync completes. Do not promise instant updates or complete detection of events the source has not published.

### Proposed check policy

These are configurable Pollmedia defaults, not claims about source publication schedules.

| Source class | Proposed check | Publication behavior |
|---|---|---|
| LGD directories | Weekly; align to monthly releases once tested | Validate codes and relationships before activation |
| SIR documents | Daily during an active revision; weekly otherwise | Treat draft, final and supplements as different editions |
| Election statistical reports | Daily around releases; monthly outside release periods | Preserve final/revised editions and reconcile totals |
| Water and employment reports | Weekly where access supports automation | Update available observations with their true dates |
| Census and annual education releases | Monthly discovery check | Add a new reference period; retain past editions |

Use supported incremental API cursors where available. For files, compare discovered release metadata and HTTP validators when reliable, then content hashes. A filename or Last-Modified header alone is insufficient. Detect replacement files at the same URL as well as new URLs.

Run imports in a queue with rate limits, retry backoff, checkpoints and a per-source lock. Re-running the same release must not duplicate records. Fetch all pages before treating an API result as a complete snapshot.

Validate schema, identifiers, row counts, geographic coverage, dates, units, totals and unexpectedly large changes. Hold failed releases for review; keep serving the last accepted version with an accurate status. A missing file or failed fetch must never delete records or imply zero values. Publish atomically and invalidate affected caches only after acceptance.

Retain superseded non-personal data and lineage to reproduce historical cards. Apply a separate minimal-retention design if personal-data processing is later included.

### Minimum provenance fields

Source agency, official landing URL, exact resource URL, source publication date, observation/reference period, retrieval time, last check time, last successful sync, content hash, dataset version, parser version, geographic version, extraction quality, license/terms reference and import status.

Keep observation date separate from publication, retrieval and check dates. Displaying 'checked today' must not make 2011 Census observations appear current.

## 2. Official source access on every data view

Each data card, chart, table and SIR result should expose:

- **View official source**: exact official release or PDF where a stable link exists.
- **Official portal**: fallback landing page if the exact file link expires or requires user interaction.
- **Data period / edition**, **last successful sync**, and a stale/error label where applicable.
- For extracted PDFs, the supporting page number when verified.

Derived indicators list their input sources and calculation. Mixed-source cards retain per-indicator links. Citizen reports are labelled Pollmedia reports; any linked official grievance reference is distinct from the citizen assertion. A government link must not imply government endorsement or verification of Pollmedia's derived output.

Store durable source identifiers and periodically check links. Never invent a document URL from assumed numbering. Do not redirect users silently to a newer edition when they requested a historical one.

## 3. SIR source evidence

SIR means Special Intensive Revision of electoral rolls.

- [ECI Electoral Roll](https://www.eci.gov.in/electoral-roll) links roll services and SIR access.
- [ECI PDF download portal](https://voters.eci.gov.in/download-eroll) exposes State and Year of Revision selection and PDF downloads.
- [Pilibhit District Election Officer portal](https://pilibhit.nic.in/deo-portal/) lists SIR Final Roll 2026, no-mapping/logical-discrepancy material, draft/ASD material, claims/objections and older rolls.

These establish discovery routes. No public bulk SIR API, recurring download permission, complete file inventory or extraction accuracy has yet been verified. Prefer ECI documents; retain CEO/DEO mirrors as explicitly attributed sources. If a source requires interactive access, mark it as assisted acquisition and keep the official portal link available. Do not bypass access controls.

## 4. Table and search experience

### Dedicated SIR page

Confirmed requirement: provide a dedicated public SIR page at `/india/sir`, accessible from the India navigation and relevant constituency pages.

Primary geographic filters: **State → Parliamentary Constituency (PC) → Assembly Constituency (AC)**. Also allow State → AC directly without requiring PC selection. Scope PC-to-AC options to the selected revision's verified electoral relationships; do not derive them from district membership. Revision year and document edition remain explicit selectors. Part/polling-station and district filters are optional refinements where supported.

After geographic selection, display available aggregate totals, revision summaries and official documents for that scope. Show a coverage label when only some ACs or parts are available. Compute PC totals only from complete, compatible, non-overlapping AC/part coverage; otherwise show clearly labelled partial totals. Never present missing coverage as zero or combine draft/final editions or incompatible dates.

Selecting a PC restricts AC options to its verified members. Selecting a different state or revision clears incompatible PC/AC selections. When an AC is selected directly, show its verified PC where available. If a historical mapping is unavailable, retain direct AC search and mark PC grouping unavailable.

Confirmed display rule: where the official source publishes the selected data publicly, show the available data in a paginated table after State/PC/AC and edition selection. Where the official source provides search-only access, offer search only and show relevant matches through an authorized search route. If no supported integration exists, link to the official search service. Search-only results must not be accumulated into a browsable table. Every result section includes its official source link and edition/date.

Geographic and edition filters may be encoded in shareable page URLs; personal search inputs and individual results must not be encoded in those URLs.

Initial screen: a short SIR explanation and empty selectors, with no full roll loaded.

Document-browser refinements after primary geographic selection:

**State → revision year → document type/edition → district (where applicable) → Assembly Constituency → part/polling station → available document**

Only offer options actually present in the source inventory. Changing an upstream selection resets incompatible downstream selections. Provide searchable dropdowns for long lists, loading and unavailable states, and paginated results fetched for the selected scope. Enforce filtering on the server; do not download the full dataset and merely hide it in the browser.

Village/PIN and PC search may guide users to compatible AC/part choices only through verified mappings. A part is not automatically a village, and part numbers are scoped by AC and edition.

Selected result:

- Official document title, jurisdiction and publication/reference dates.
- Explicit draft/final/supplement/discrepancy-list label.
- Verified extracted geographic metadata and aggregate totals where provided.
- Extraction confidence/review status and source page references.
- **Open official PDF** and **Open ECI portal** controls.

Area totals remain available where published. Public-table mode does not require identifying search input; it shows source-supported rows for the selected scope. Search-only mode requires identifying input and returns relevant matches. Record source access mode per dataset/edition, rather than assuming all SIR sources support the same display mode. Public availability, permitted republication, field scope and extraction quality must be assessed separately before release.

### Search-only mode and optional search within public tables

- In search-only mode, require an explicit search submission with sufficiently specific identifying details. Exact supported fields will be set after source inspection; do not request identifiers absent from the source or unrelated identity documents.
- Validate and filter on the server. Broad or ambiguous searches ask for more details; do not return an entire part's roll as a fallback.
- Show only relevant matches and the minimum fields needed to distinguish them. Mask unnecessary identifiers and omit unrelated personal information.
- Include the official source link, edition/date and verified PDF page reference with each match. Keep summaries independently accessible.
- Treat matches as source-record matches, not authenticated ownership or verified current residence. No match means no match in the selected indexed edition, not proof that the person is absent from the official roll.
- Apply request limits and prevent enumeration of search-only sources. Keep personal search inputs out of URLs, analytics and routine logs; exclude individual search results from search-engine indexing and shared caches. Public tables use server-side pagination and the reviewed field scope.
- Validate acquisition and permitted reuse before serving extracted personal records. If an authorized local lookup route cannot be established, expose the official ECI search route and clearly state that local lookup is unavailable.

## 5. PDF extraction pipeline

1. Discover and record official document links, jurisdiction, revision year, language, edition and publication date.
2. Download authorized available documents; record checksum and provenance. Distinguish revised content from duplicate mirror files.
3. Detect text PDFs versus scanned pages; use text extraction first and OCR only where necessary.
4. Extract summary/geographic fields with their page references. Keep absent or unreadable fields null. Do not derive precise counts from unreliable OCR.
   For the confirmed individual-lookup feature, first define the minimum permitted record fields, access controls and retention policy; then validate record-level extraction against source pages before enabling search. Do not ingest every personal field simply because it appears in a PDF.
5. Validate state, AC and part identifiers against the inventory; test multi-page tables, rotated pages, Hindi/English text and changed layouts.
6. Compare extracted summary totals against printed totals. Route failures and low-confidence output to review.
7. Activate the reviewed dataset version and refresh only relevant filtered results.

Separate document types in storage and display. Inclusion in a discrepancy list is not proof of deletion, fraud or ineligibility. Do not infer a person's status from their absence in an incompletely fetched or parsed roll. Numerical differences between editions are not automatically counts of additions/deletions; use official revision totals where available.

## 6. SIR additions to the source matrix

| Item | Target field | Native level | Feasibility |
|---|---|---|---|
| SIR document inventory | Revision year, edition, official PDF link | State/AC/part as published | Official routes located; full inventory untested |
| Roll geography | AC, part number and polling-station label | Published part | Extraction to validate on pilot documents |
| Published elector total | Printed roll total | Part/AC where printed | Conditional on explicit summary fields |
| Published revision statistics | Additions/deletions/corrections counts | As published | Conditional; no inferred person-level classification |
| Edition comparison | Comparable aggregate changes | Same defined geography and period | Requires comparable versions and complete coverage |
| Public table / search-only display | Publicly published rows as a table; otherwise authorized relevant-match search | Selected revision/AC/part | User confirmed; source access mode, reuse and extraction to validate |

## 7. Acceptance criteria

- A changed test source is detected, validated and reflected on its associated page with new provenance.
- An unchanged source produces no duplicate import; failed/partial acquisition preserves accepted data.
- New editions coexist with history; draft/final status is visible.
- Dropdowns return only the selected scope, and incompatible selections reset correctly.
- The dedicated SIR page supports State → PC → AC and direct State → AC selection. PC options use versioned electoral mappings, including cross-district membership.
- Aggregate results label incomplete coverage and never combine incompatible editions. All geographic result sections expose official links.
- Public-table datasets display reviewed rows for the selected geographic scope without requiring identifying input. Search-only datasets return no individual records for empty or geography-only requests; specific searches return relevant matches and ambiguous searches require refinement.
- Individual results carry official references, respect field minimization, and do not assert identity verification. Enumeration controls and sensitive-log exclusions are tested before release.
- Extracted summary values trace to reviewed PDF pages; OCR errors cannot silently publish.
- Each displayed official-data result offers a working official link or a clearly labelled portal fallback.
- No dataset is labelled live or synchronized until its acquisition and refresh path has actually been tested.

