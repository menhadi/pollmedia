# PC/AC blank-turnout audit — 1 October 2026

Scope: only blank or literal-zero elector/voter fields in archived PC and AC constituency records. This audit read all 448 locally preserved source editions and 74,218 constituency tables, then overlaid the exact previously imported election correction ZIPs. These are source tables, not a count of accepted election contests or a claim of national completeness. All four packages below were imported on 1 October; the server reindexed the same 74,218 source tables after each import. Representative public reports confirm the new values, while the residual counts below remain an offline projection until a live zero-value query is run.

## Source-verified corrections imported

| Package | Records with previously blank turnout | Source check |
| --- | ---: | --- |
| Bihar AC 2005 February/October | 486 | Both official reports matched to all 243 seats per round by name and valid-vote total; round identity retained. |
| AC PDF summary v3, 30 editions | 350 | Official summary seat code/name, elector total, and printed voter total; source discrepancies retained as notes. |
| Arunachal Pradesh AC 2014 | 49 | Saved word coordinates from the official scanned summary; seat identity, electors, and printed turnout percentage agree. Eleven source pages print zero polling or lack a supported positive total. |
| Gujarat AC 2012 | 149 | Official detailed-page “TURNOUT TOTAL” with matching seat/electors, general plus postal votes, and printed percentage. Thirty-three remaining seats have ambiguous saved OCR. |
| **Total** | **1,034** | Existing candidate rows, nonzero values, source files, URLs, and warnings remain preserved. |

After these imports, the offline audit projects **384 AC** and **27 PC** records with blank/zero voter totals in the same 448 editions and 74,218 tables. The election PDFs/workbooks are locally present for every residual row, but file presence alone does not establish a usable positive value. The audit also recorded 121 AC blank/zero elector fields (often overlapping the 384) and 334 AC plus 43 PC **nonzero voter-greater-than-elector** conflicts; those nonzero conflicts are outside this zero-only correction.

The projected AC/PC blank-voter counts by year after import are:

| Year | AC | PC |
| ---: | ---: | ---: |
| 1951 | 32 | 5 |
| 1955 | 1 | 0 |
| 1957 | 35 | 7 |
| 1962 | 47 | 3 |
| 1964 | 15 | 0 |
| 1967 | 36 | 7 |
| 1971 | 0 | 1 |
| 1972 | 33 | 0 |
| 1974 | 2 | 0 |
| 1975 | 1 | 0 |
| 1977 | 1 | 2 |
| 1978 | 2 | 0 |
| 1980 | 3 | 1 |
| 1983 | 8 | 0 |
| 1984 | 2 | 0 |
| 1985 | 1 | 0 |
| 1987 | 1 | 0 |
| 1989 | 1 | 1 |
| 1990 | 3 | 0 |
| 1991 | 1 | 0 |
| 1992 | 2 | 0 |
| 1993 | 1 | 0 |
| 1998 | 43 | 0 |
| 1999 | 5 | 0 |
| 2002 | 2 | 0 |
| 2004 | 11 | 0 |
| 2009 | 3 | 0 |
| 2012 | 33 | 0 |
| 2014 | 26 | 0 |
| 2016 | 2 | 0 |
| 2017 | 12 | 0 |
| 2018 | 4 | 0 |
| 2019 | 3 | 0 |
| 2023 | 1 | 0 |
| 2024 | 10 | 0 |
| 2026 | 1 | 0 |
| **Total** | **384** | **27** |

The exact year-by-year record counts and reasons are in `exports/pc-ac-zero-audit-20261001-summary.json`; each residual source URL, edition, constituency, and warning is in `exports/pc-ac-zero-audit-20261001-gaps.csv`. These exports project the state **after** all four imported correction packages. They do not imply that all listed files have a recoverable number. Previously printed discrepancies remain visible with a review marker and a source link; no votes were inferred from turnout percentages or unrelated collection labels.

## Live import check on 1 October

The Bihar February/October 2005 revision is visible in the live election report. The first 30-edition PDF-summary import stopped at a prior-revision checksum conflict. A read-only comparison of all 30 live database checksums with the v3 manifests found two editions already at the v3 target, 24 at the expected predecessor, and four at earlier preserved revisions: Gujarat 2007, Jharkhand 2014, Himachal Pradesh 2007, and Himachal Pradesh 2012. None was an unknown checksum.

`pollmedia-pc-ac-zero-turnout-live-bridge-20261001.zip` preserved those four exact earlier revisions as snapshots, then advanced them to the already source-verified v3 target. The bridge filled 321 previously blank turnout rows within those four editions, including earlier v5/v6/v7 corrections; it did not change candidate rows or existing nonblank totals. The bridge, original v3, Arunachal 2014, and Gujarat 2012 import scripts all completed with checksum verification and constituency reindexing. Public reports then showed Gujarat 2007 code 1 at 87,916 votes polled, Himachal 2012 code 2 at 48,270, Arunachal 2014 code 2 at 7,996, and Gujarat 2012 code 1 at 143,451. These spot checks establish that the corrections are visible; they do not certify every record or national completeness. Each import guarded the predecessor checksum, review overlays, local-file shadowing, and at least 10 GiB free server disk.

## Follow-up source review: residual AC import completed

The 384 AC and 27 PC figures above count archived records whose `votes_polled` is blank or literally zero. They do **not** mean 411 elections had zero voters or that every row is repairable from the saved source. A second review found official, printed positive voter totals for 57 AC records in six editions: Uttar Pradesh 1951 (7), Gujarat 2012 (33), Jharkhand 2014 (7), Andhra Pradesh 2014 (1), Maharashtra 2014 (7), and Chhattisgarh 2018 (2). Gujarat's 33 scanned rows were checked against the PDF's constituency heading, electorate, general/postal components, total, and printed percentage. For Mahuva, the saved OCR had misread 181,028 electors as `3`; the PDF corrects that too. For Matar, the saved OCR read 152,634 votes, whereas the printed general (151,615) plus postal (1,016) and printed total agree at **152,631**. Earlier saved OCR and all source files remain preserved.

The `pollmedia-ac-residual-turnout-corrections-20261001-v4.zip` bundle contains those 57 source-verified changes. It snapshots each exact predecessor JSON, keeps candidates and existing nonblank vote totals intact, and records source pages, file hashes, OCR identity, and prior warnings. Its data-only importer checks the prior checksum, review overlays, and a 10 GiB server disk reserve. On 1 October, the user verified the uploaded ZIP checksum; a read-only live database query then found all six extraction JSON rows at the bundle's exact new SHA-256 values. A second read-only query found every indexed row at the same new revision: 347/347 Uttar Pradesh 1951, 182/182 Gujarat 2012, 81/81 Jharkhand 2014, 294/294 Andhra Pradesh 2014, 288/288 Maharashtra 2014, and 90/90 Chhattisgarh 2018. Thus the archive revision and constituency index are complete for the six editions. A public-page spot check remains separate from these database checks. Generated local audit projections are in `exports/pc-ac-residual-after-20261001-v4.json` and `.csv`.

After that import, the **projected** residual is 327 AC and 27 PC source records, or 354 total. Within AC: 208 have literal zero, including 14 with an explicit uncontested note; 80 have a blank value and an uncontested note; 23 are blank without positive candidate votes; and 16 are blank although candidate votes are printed. Of the other 194 literal-zero AC rows, 191 have just one zero-vote candidate and three Arunachal 2019 rows have a candidate plus NOTA with missing vote cells. All 27 PC residual rows have an uncontested note, including 25 literal zeros. Sampled original pages explicitly say “uncontested” or print zero voters. A zero here can therefore represent **no poll** rather than missing turnout. The remaining 16 positive-candidate AC rows include 11 Uttar Pradesh 2017 seats whose saved detailed workbook gives valid votes plus NOTA but not a separately confirmed turnout total, four Arunachal Pradesh 2004 detailed pages whose turnout total is blank, and one Manipur 2017 workbook seat with valid votes plus NOTA. Those component sums must not be silently relabeled as total voters. Keep their source rows and warnings visible while seeking a Form 20 or other official turnout figure.
