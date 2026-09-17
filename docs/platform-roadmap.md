# Pollmedia platform scope and delivery plan

Confirmed scope: the project covers the full civic-data platform discussed in the original chat. The accepted SIR prototype establishes a useful pattern for filtering, source links and imports; it does not narrow Pollmedia to SIR or approve all of the final product design.

## Product

Help people understand a place, examine official development and election data, report public-service issues, identify responsible authorities and track responses over time.

India first, Pilibhit pilot, with a geographic model adaptable to other countries. Retain the Pollmedia domain and brand; build the new application separately from the legacy site.

## Connected modules

| Module | Intended behavior | Pilot status |
|---|---|---|
| Geography and maps | Search village, ward, GP, block, district, PIN, AC or PC; explore linked administrative and electoral areas | Census/boundary identifier comparison sampled; current LGD, geometry validation and electoral joins pending |
| Place pages | Village/GP/block/district/AC/PC/state profiles with maps, data, issues and sources | Laravel district/PC pages implemented with source-backed Census and directory snapshots; maps and other geography levels pending |
| Development cards | Dated population, education, health, water, infrastructure and livelihood indicators at supported geographic levels | Census sample imported; other sources remain conditional |
| Elections | Results, candidates, parties, turnout, vote shares, margins and historical comparisons using versioned boundaries | Source discovery only |
| SIR and electoral rolls | Dedicated page; public tables where available and permitted, otherwise relevant-match search or official search access | Bounded part-summary prototype; final rolls and individual features pending |
| Citizen issues | Location, category, evidence, same-problem support, discussions and a status history | Not implemented |
| Representatives and authorities | Link places to MPs, MLAs, other elected representatives, district officers and relevant departments; maintain effective-dated officeholders and refresh official directories as appointments change | Shared office/person/assignment tables, profile links and tested reviewed-replacement service implemented; automated discovery and refresh pending |
| Responses and resolution | Official grievance references, authority responses and citizen confirmation of resolution | Not implemented |
| Community surveys | Safety, harmony, trust and service satisfaction, with sampling methodology and uncertainty | Not implemented |
| Comparisons and trends | Compare places and time periods only when geography, indicators and denominators are compatible | Not implemented |
| Data administration | Source registry, recurring imports, validation, change review, versions, freshness and failure visibility | Local on-demand import/versioning proof; scheduled workers pending |

## Shared behavior across modules

- Reuse geographic entities and effective-dated relationships. A district is not interchangeable with a constituency; a part is not automatically a village.
- Provide relevant location/time filters and show only available coverage. Filter types should suit each module rather than forcing PC/AC selectors onto all datasets.
- Link every official fact to its source and period. Distinguish source updates, retrieval dates and observed years.
- Import APIs, Excel/CSV and PDFs through source-specific validated pipelines. Schedule refreshes in the deployed application; do not depend on official websites at page-view time.
- Keep official measurements, citizen reports and survey perceptions distinct.
- Store one citizen issue and reference it across relevant geographic views without duplicate counts.
- Maintain a shared, source-backed directory of elected representatives and administrative officeholders. Link issues to stable offices/jurisdictions and resolve the current officeholder separately; retain earlier tenures and historical responses. See [authority directory specification](authority-directory-spec.md).
- Link each representative/officer to verified public profiles, prioritizing official biographies and supplementing with Wikipedia or other reliable profiles where available. Profile links follow the person when officeholders change.
- Display tables, maps, charts or search according to the task, source access and field permissions. The public-table/search-only rule for SIR is not a blanket rule for all citizen or survey data.
- Use explicit missing-data and partial-coverage states. Do not invent values, infer eligibility from a missing record, or publish an overall score before defining its methodology.

## Delivery order

### 1. Common foundation

Define the master geography, source registry, indicator, election, issue and authority schemas. Validate the pilot crosswalk and acquire current LGD relationships. Establish a clean Laravel application with PostgreSQL/PostGIS, React/Inertia and queues, after checking the local environment. The existing Python/SQLite prototype is a disposable data proof, not the production architecture.

### 2. First integrated place page

Create a Pilibhit district page and a separate PC page. Connect village search, a verified map, the Census baseline and source/freshness panels. Make uncovered sections explicitly unavailable rather than filling them with sample numbers.

### 3. First development and election datasets

Add one validated water dataset and one final election-results dataset. Extend to education, health and employment as access and coverage are established. Preserve each indicator's native reporting geography and date.

### 4. Citizen issues and responsibility

Implement reporting, evidence, location mapping, community confirmation, moderation, authority ownership and the resolution timeline. Validate an end-to-end example from village report to district/AC/PC views.

### 5. SIR integration

Bring the accepted filtering/source-link pattern into the main application. Add supported final-roll and revision editions, with public-table or search-only behavior based on each source. Keep SIR as a module accessible from place pages and its dedicated page.

### 6. Broader development cards, surveys and comparisons

Expand supported indicators and geography. Introduce survey collection with an explicit sampling design, then place comparisons and longitudinal development views. Avoid attributing all development changes to MPs/MLAs.

### 7. Operational launch

Finish scheduled imports, error review, security/access controls, backups, publication permissions and useful public-page indexing. Deploy only after the integrated pilot is validated. Preserve the current public site until the replacement is ready.

## Immediate next work

The [shared database design](database-design.md), first foundation migration and integrated Laravel district/PC pages are implemented. Next: validate current LGD relationships and PostGIS setup, then add the first verified water and election imports alongside the authority refresh workflow. Additional SIR-only work must not replace this broader objective.

## Confirmed election and report scope

Election history will include winner, election-time party, votes/share, runner-up, margin in votes and percentage points, and turnout where sourced. Current representative party/term/profile is separate from election-time party; by-elections and boundary changes retain dates.

Automated quarterly and annual district, state and country reports are planned: public report pages and PDFs based on fixed snapshots, comparable periods, official source links, data gaps and archived editions. Aggregation must not double-count overlapping district/constituency geographies. Review-before-publication is an option. Scheduling and publication are not active.

The first interactive village map is now implemented below the title. Current LGD relationships, official PC/AC outlines and thematic map joins are still pending.
