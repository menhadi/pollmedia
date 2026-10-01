# PC/AC blank-turnout audit — 1 October 2026

Scope: only blank or literal-zero elector/voter fields in archived PC and AC constituency records. This audit read all 448 locally preserved source editions and 74,218 constituency tables, then overlaid the exact previously imported election correction ZIPs. These are source tables, not a count of accepted election contests or a claim of national completeness. The four packages below are **prepared, not yet confirmed live**.

## Source-verified corrections prepared

| Package | Records with previously blank turnout | Source check |
| --- | ---: | --- |
| Bihar AC 2005 February/October | 486 | Both official reports matched to all 243 seats per round by name and valid-vote total; round identity retained. |
| AC PDF summary v3, 30 editions | 350 | Official summary seat code/name, elector total, and printed voter total; source discrepancies retained as notes. |
| Arunachal Pradesh AC 2014 | 49 | Saved word coordinates from the official scanned summary; seat identity, electors, and printed turnout percentage agree. Eleven source pages print zero polling or lack a supported positive total. |
| Gujarat AC 2012 | 149 | Official detailed-page “TURNOUT TOTAL” with matching seat/electors, general plus postal votes, and printed percentage. Thirty-three remaining seats have ambiguous saved OCR. |
| **Total** | **1,034** | Existing candidate rows, nonzero values, source files, URLs, and warnings remain preserved. |

After these proposed imports, the same 448 editions and 74,218 tables would have **384 AC** and **27 PC** records with blank/zero voter totals. The election PDFs/workbooks are locally present for every residual row, but file presence alone does not establish a usable positive value. The audit also recorded 121 AC blank/zero elector fields (often overlapping the 384) and 334 AC plus 43 PC **nonzero voter-greater-than-elector** conflicts; those nonzero conflicts are outside this zero-only correction.

The AC/PC blank-voter counts by year after proposed imports are:

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

The exact year-by-year record counts and reasons are in `exports/pc-ac-zero-audit-20261001-summary.json`; each residual source URL, edition, constituency, and warning is in `exports/pc-ac-zero-audit-20261001-gaps.csv`. These exports project the state **after** all four prepared correction packages. They do not imply that all listed files have a recoverable number. Previously printed discrepancies remain visible with a review marker and a source link; no votes were inferred from turnout percentages or unrelated collection labels.

## Live import check on 1 October

The Bihar February/October 2005 revision is visible in the live election report. The 30-edition PDF-summary import stopped at a prior-revision checksum conflict before completing; the Arunachal 2014 and Gujarat 2012 revisions are not yet visible in their live reports. A read-only comparison of all 30 live database checksums with the v3 manifests found two editions already at the v3 target, 24 at the expected predecessor, and four at earlier preserved revisions: Gujarat 2007, Jharkhand 2014, Himachal Pradesh 2007, and Himachal Pradesh 2012. None was an unknown checksum.

`pollmedia-pc-ac-zero-turnout-live-bridge-20261001.zip` preserves those four exact earlier revisions as snapshots, then advances them to the already source-verified v3 target. The bridge fills 321 previously blank turnout rows within those four editions, including earlier v5/v6/v7 corrections; it does not change candidate rows or existing nonblank totals. Import the checksum-verified bridge first, rerun the original v3 import, then import the separate Arunachal 2014 and Gujarat 2012 bundles. Each import checks its predecessor checksum, review overlays, local-file shadowing, and at least 10 GiB free server disk. Recheck public reports and database counts after import before describing the projected residual counts above as live.
