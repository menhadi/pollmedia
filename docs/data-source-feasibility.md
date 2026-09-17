# Pollmedia India: data-source feasibility matrix

Research date: 15 September 2026. Scope: Pilibhit pilot. This is source discovery and an implementation plan, not an audited import or a guarantee of current coverage.

## Decision

Proceed with a geography and Census proof of concept, then election results and one water profile. Official catalogues provide promising foundations. Bulk access, exact joins, dataset dates and republication terms must be checked against downloaded samples before committing to production imports.

Pilibhit district and Pilibhit parliamentary constituency must be separate entities. The district site lists ACs 127–130; the Bareilly government site also identifies AC 118 Baheri under PC 26 Pilibhit. A complete PC pilot therefore needs geography outside Pilibhit district. [District constituency list](https://pilibhit.nic.in/constituencies-2/), [Bareilly election document index](https://bareilly.nic.in/anulagnak-6/).

## Evidence levels

- **Listed:** official catalogue explicitly lists a relevant dataset/download; bytes and rows not validated.
- **Conditional:** relevant portal/report exists, but access, required fields or pilot coverage remain unproven.
- **Own collection:** proposed Pollmedia measure; no government feed established.

The original matrix below records discovery-stage status. Subsequent bounded import evidence is documented in [the pilot report](../pilot/README.md): Census, a SIR enumeration PDF and SOI attributes have now been sampled. An API advertised in a catalogue is not a tested endpoint. A webpage update date is not the observation date of its data.

## Source register

Row IDs below inherit access, dates, history, join and reuse notes from this register.

| ID | Publisher and official source | Access observed | Geography / join | Time / history | Reuse and reliability limit |
|---|---|---|---|---|---|
| S1 | Ministry of Panchayati Raj: [LGD on OGD](https://data.gov.in/catalog/local-government-directory-lgd) | Catalogue advertises downloads and APIs; no endpoint tested | LGD entities; village and local-body PIN resources listed | Monthly publication described; historical snapshots not established | NDSAP catalogue; capture resource-level GODL attribution. Directory identity does not establish polygon accuracy. |
| S2 | Survey of India: [village boundary downloads](https://surveyofindia.gov.in/pages/village-boundary-data-base-of-entire-india), [ABDB](https://surveyofindia.gov.in/pages/administrative-boundary-data-base-abdb-) | Uttar Pradesh ZIP listed; administrative data and metadata links available | Village/district/subdistrict geometry; actual identifier fields uninspected | Dataset vintage and historical releases to inspect | Official geometry candidate; preserve metadata and establish redistribution terms. Listing is not evidence of complete Pilibhit coverage. |
| S3 | ORGI, Ministry of Home Affairs: [Pilibhit PCA](https://censusindia.gov.in/nada/index.php/catalog/6342) | XLSX explicitly listed | Census 2011 village/town/ward codes; LGD crosswalk required | 2011 baseline; no current population claim | Official aggregates; verify source terms and column definitions before publication. |
| S4 | ORGI: [Pilibhit District Census Handbook](https://censusindia.gov.in/nada/index.php/catalog/1273) | PDF, inset ZIP and UP amenities XLSX listed | Census units; crosswalk to current geography required | 2011 reference; publication date differs | Historical amenities, not present service availability. Verify workbook coverage and reuse terms. |
| S5 | ECI: [statistical archive](https://www.eci.gov.in/statistical-reports), [2024 OGD catalogue](https://data.gov.in/catalog/general-election-lok-sabha-2024-statistical-reports-data) | Archive lists elections back to 1951; OGD catalogue found; direct 2024 page did not render useful content | State + election + constituency type/number + boundary version; not LGD alone | Election-specific; formats vary by year | Prefer final statistical reports. Download and reconcile pilot tables; verify applicable reuse terms. |
| S6 | UP CEO: [old/new delimitation table](https://ceouttarpradesh.nic.in/Instructions/delimitation_Old_New.pdf); district sources above | Official PDF/table references | AC/PC identity and relationship evidence | Delimitation-specific; not a full polygon history | PDF correspondence cannot prove unchanged territory. Machine-readable electoral boundaries remain unresolved. |
| S7 | Department of Drinking Water and Sanitation, Jal Shakti: [JJM Village Profile](https://ejalshakti.gov.in/JJM/JJMReports/profiles/rpt_VillageProfile.aspx) | Report supports LGD village-code lookup; no documented public bulk API verified | Village LGD input and JJM IDs; habitation distinctions matter | Template shows historic connection dates through 2026; selected village values untested | Reported connections do not measure continuous safe supply. Verify extraction permission and terms. [Official UP sample](https://jjm.up.gov.in/Content/ISAActivitiFiles/ISA1078_638742671102640204.PDF) shows household and connection fields, but is not Pilibhit. |
| S8 | Ministry of Education: [UDISE+](https://www.udiseplus.gov.in/), [data sharing](https://microdata.udiseplus.gov.in/) | Public dashboard/school links; data-sharing download requires login | School UDISE code; LGD or geographic crosswalk unverified | Academic-year reporting; archives linked | Detailed bulk access and reuse subject to portal terms. No student records needed. |
| S9 | MoHFW: [UP subdistrict HMIS catalogue](https://www.data.gov.in/catalog/hmis-sub-district-level-item-wise-monthly-report-uttar-pradesh) | Catalogue discovered; no pilot resource downloaded | Reporting subdistrict; correspondence to LGD unverified | Monthly series described; latest available observation unknown | Do not allocate facility activity to residents or infer village rates. Resource definitions, denominators and GODL applicability to verify. |
| S10 | Ministry of Rural Development: [MGNREGA portal](https://nrega.dord.gov.in/MGNREGA_new/home_nrega_new.aspx) | Reports and GP navigation evident; no bulk endpoint tested | Scheme GP/block codes; LGD crosswalk unverified | Financial-year reporting; retained history to inspect | Use aggregate employment/work data. GP-level target fields and reuse route still conditional. |
| S11 | Pollmedia | New reporting and survey collection | Issue coordinates plus verified place relationships | Timestamped events; survey waves | Product methodology and consent required; not an official dataset. |

The [Government Open Data License](https://www.data.gov.in/sites/default/files/Gazette_Notification_OGDL.pdf) provides conditional reuse with attribution and non-endorsement requirements, and excludes personal information. Do not infer that every government website or map is covered merely because it is public.

## 40 candidate indicators

Geography prerequisites (LGD IDs, PIN associations, administrative polygons and electoral relationships) are separate from these indicators. Status applies to the proposed source, not to completed import work.

| # | Indicator | Source | Native level | Status | Definition / pilot check |
|---|---|---|---|---|---|
| 1 | Population | S3 | Village/town/ward | Listed | Retain 2011 label |
| 2 | Households | S3 | Village/town/ward | Listed | Preserve household universe |
| 3 | Male population | S3 | Village/town/ward | Listed | Counts |
| 4 | Female population | S3 | Village/town/ward | Listed | Counts |
| 5 | Population aged 0–6 | S3 | Village/town/ward | Listed | Age-band baseline |
| 6 | Scheduled Caste population | S3 | Village/town/ward | Listed | Aggregates only |
| 7 | Scheduled Tribe population | S3 | Village/town/ward | Listed | Aggregates only |
| 8 | Literate population | S3 | Village/town/ward | Listed | Counts; age definition matters |
| 9 | Literacy rate | S3 | Village/town/ward | Listed | Derive with population aged 7+, not total population |
| 10 | Sex ratio | S3 | Village/town/ward | Listed | Female / male × 1,000; null if denominator zero |
| 11 | Education-facility availability | S4 | Village | Listed | Historical categories; inspect sheet |
| 12 | Medical-facility availability | S4 | Village | Listed | Historical categories; inspect sheet |
| 13 | Drinking-water facilities | S4 | Village | Listed | Historical access categories |
| 14 | Electricity availability | S4 | Village | Listed | Historical service classification |
| 15 | Transport/communication facilities | S4 | Village | Listed | Choose exact columns after inspection |
| 16 | Candidate votes | S5 | AC/PC | Listed | Final election-specific totals |
| 17 | Candidate vote share | S5 | AC/PC | Listed | Use stated denominator including NOTA treatment |
| 18 | Winning margin | S5 | AC/PC | Listed | Top two vote totals; handle uncontested elections |
| 19 | Turnout | S5 | AC/PC | Listed | Preserve official definition and electors denominator |
| 20 | Number of electors | S5 | AC/PC | Listed | Election-specific electorate |
| 21 | JJM household denominator | S7 | Village | Conditional | Retain household reference date |
| 22 | Household tap connections | S7 | Village | Conditional | Select and inspect a Pilibhit village |
| 23 | Household tap-connection coverage | S7 | Village | Conditional | Connections / matched household denominator |
| 24 | Historical tap connections | S7 | Village | Conditional | Verify non-placeholder time-series values |
| 25 | Piped-water-supply availability | S7 | Village | Conditional | Scheme/report status, not service reliability |
| 26 | School count by management | S8 | School → geography | Conditional | Obtain stable school IDs and year |
| 27 | Enrolment | S8 | School → geography | Conditional | Aggregate only; school location is not pupil residence |
| 28 | Teacher count | S8 | School → geography | Conditional | Verify sanctioned versus working definitions |
| 29 | School toilet availability | S8 | School → geography | Conditional | Inspect field and functionality definition |
| 30 | School electricity availability | S8 | School → geography | Conditional | Inspect field and year |
| 31 | Antenatal-care registrations | S9 | Reporting subdistrict | Conditional | Locate exact item; service count, not prevalence |
| 32 | Institutional deliveries reported | S9 | Reporting subdistrict | Conditional | Confirm reporting universe and completeness |
| 33 | Immunisation services reported | S9 | Reporting subdistrict | Conditional | Specify vaccine/age item before use |
| 34 | MGNREGA households employed | S10 | GP target | Conditional | Validate GP report and financial year |
| 35 | MGNREGA person-days | S10 | GP target | Conditional | Person-days are not unique workers |
| 36 | Open citizen issues | S11 | Issue → geography | Own collection | Unique issues and verification status |
| 37 | Citizen-confirmed resolutions | S11 | Issue → geography | Own collection | Separate government disposal from confirmation |
| 38 | Perceived safety | S11 | Survey geography | Own collection | Sampling, period and uncertainty required |
| 39 | Perceived social harmony | S11 | Survey geography | Own collection | Survey design required; no score from complaint counts |
| 40 | Trust in local institutions | S11 | Survey geography | Own collection | Publish respondent count and methodology |

## Corrections to the earlier discussion

1. LGD is a useful identity backbone, not a universal immutable join key. Store changes, splits, mergers, source identifiers and effective dates.
2. Administrative and electoral geography overlap. A single parent chain cannot represent them. Blocks and tehsils also need independently validated relationships.
3. PIN associations support search. They do not prove an exact residence or a postal boundary polygon.
4. PostGIS can classify a point only when the relevant boundaries are available and sufficiently reliable. Near-boundary uncertainty requires explicit handling.
5. An official table can still be stale, incomplete or based on a different reporting geography. Never replace missing values with zero.
6. No resident-verification API was established. SIR document discovery, PDF extraction and area summaries are confirmed requirements. Officially public datasets should be displayed as filtered tables; sources providing search-only access should offer relevant-match search only. Both modes retain official links. Acquisition, permitted reuse and extraction still need validation. A roll match alone is not proof that the current user is that elector.
7. Source availability does not justify a combined development score. Start with sourced indicators; define weights and statistical validity before adding scores.

## First implementation milestone

Build a reproducible data proof before the full application:

1. Download the Pilibhit PCA workbook, LGD pilot records and SOI Uttar Pradesh boundary archive. Save original files, retrieval URLs, timestamps and SHA-256 checksums.
2. Inspect schemas and boundary metadata. Identify Census-to-LGD and geometry-to-LGD joins; preserve unmatched records and split/merge cases for review. No name-only automatic acceptance.
3. Build a small crosswalk for 10–20 villages selected to include name ambiguity and boundary cases. Include the district/PC distinction and Baheri coverage in scope.
4. Obtain electoral boundary evidence sufficient for AC/PC assignment. If authoritative vector coverage cannot be established, show unresolved mapping rather than presenting guesses as verified.
5. Join Census indicators and one real JJM village profile. Verify source date, denominator and missingness.
6. Import a final Pilibhit election table when acquired. Reconcile totals against the official report.
7. Produce one evidence-backed village card and separate district and PC pages. Display coverage gaps and source links.

### Acceptance evidence

- Download manifest and schema notes for every source used.
- Counts of matched, ambiguous and unmatched entities; documented review decisions.
- Valid geometries, detected gaps/overlaps and boundary version recorded.
- No duplicated issues across aggregation levels; ratios recalculated from compatible numerators and denominators.
- No district health values presented as village measurements.
- No use of current boundaries to silently restate historic election territories.
- Resource terms/attribution recorded before public release.

## When office assistance may be needed

These are possible requests, not messages already sent:

| Gap | Office/source to approach | Concrete request |
|---|---|---|
| Current village/GP mappings and change history | LGD data owner / district panchayat office | Machine-readable codes, mappings and effective dates |
| AC/PC vector boundaries and official assignments | UP CEO / District Election Office | Boundary version, village/ward assignments and reuse terms |
| Boundary identifier/vintage discrepancies | Survey of India | Metadata, code system and harmonization details |
| Repeatable village water export | JJM / UP water department | Aggregate village export keyed by LGD, dates and permission |
| School-level aggregate bulk access | UDISE+ data-sharing channel | School aggregates, location identifiers, years and reuse terms |
| Current HMIS aggregates and completeness | UP NHM / district health office | Reporting-unit dictionary, period coverage and aggregate export |

## Research limits

At initial discovery, only official pages and catalogue entries were checked. The subsequent [local pilot](../pilot/README.md) documents downloaded files, sample imports and code-match counts. No API has been authenticated; full Pilibhit data completeness, current LGD relationships and geometry validity remain unverified. Health, education and employment rows remain conditional.

## Added requirements: synchronization, SIR and official links

User requirements recorded on 15 September 2026:

- Pollmedia should pick up future official-data changes through recurring synchronization, including changes to published Excel and PDF files.
- Add Special Intensive Revision (SIR) data and PDF extraction, with dropdowns that show the selected scope rather than all records.
- Display officially public data in a filtered, paginated table. Otherwise offer search only through an authorized route, with relevant matches and an official-search fallback. Determine mode per source and edition.
- Provide a dedicated `/india/sir` page with State/PC/AC filters, revision selection, aggregate results and official document links; support direct State-to-AC selection as well.
- Always provide a link to the corresponding official data in the platform.

See [Synchronization and SIR specification](sync-and-sir-spec.md). This describes application behavior to implement; no running sync service or Codex monitoring automation has been created.

