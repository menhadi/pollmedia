# Civic source staging checkpoint — 25 September 2026

All work below is in `/home/pollmedia/census-worker/`, outside the live web application and PostgreSQL database. The five-minute cron remains single-worker, low-priority and lock-protected.

## Census and LGD raw evidence

- The saved 1991 Census review database has 118 source workbooks and 697,205 original data rows independently matched to the original XLSX cells.
- The 2001/2011 package has five source references, three distinct originals and 30,624 raw rows including headers and notes. Three 2011 scope references point to the same original workbook and must not be counted three times.
- The preserved Uttar Pradesh LGD administrative ZIP and Pilibhit electoral workbook completed extraction after an OOXML stylesheet error was handled by a raw-cell fallback. The Pilibhit workbook contributed 1,956 rows. The isolated review database now has three completed jobs, five distinct extracted workbooks, seven source references and 360,700 raw rows, including headers and notes. `PRAGMA quick_check` returned `ok`.
- The fallback retains sparse source positions, numeric lexemes as strings, style identifiers, formulas and cached values without interpreting LGD codes or silently repairing the malformed stylesheet. The affected original remains checksum-preserved. Geography identities and crosswalks are not yet validated.

## 2011 Pilibhit District Census Handbook, Part A

- Exact official download: https://censusindia.gov.in/nada/index.php/catalog/1272/download/4102/DH_2011_0920_PART_A_DCHB_PILIBHIT.pdf
- Original PDF SHA-256: `75bcc55b7e456ad259d1b106b6a5a15e6edddaceaea07e8c5d5dcb445a107e6d`; 3,841,854 bytes. The server copy matched the local downloaded original.
- `pdfinfo` found 456 pages. Low-priority `pdftotext -layout` extraction preserved page-numbered, unverified text for 453 pages; three pages had no text. The page JSONL has exactly 456 records, SHA-256 `61963946fe5f0fa169cadae7426681657e68151aaec874fe5c0b4ed242198bcb`. The raw text SHA-256 is `58143fccba70bc8d9b291eb90a06fc9fccc4ba9077bf004a49a2aeafd1a58a38`.
- The original is under `source-originals/` and page evidence under `source-evidence/` in the isolated worker directory. A verified local HTTPS download was transferred because the server's `curl` could not validate the site's TLS certificate chain. No TLS bypass was used.
- Page text is not a structured amenity table. The next step is to identify the Village and Town Directory table layouts, compare candidate rows and codes to the printed pages, and explicitly review the three textless pages before any OCR. Keep 2011 Census geography separate from current LGD codes and effective dates.

No civic data was written to live PostgreSQL, no web code was deployed, and no publication claim follows from these raw-row counts. The 10 GiB server reserve remained intact.

## Explicit historical village-code evidence

The separate Pilibhit PCA 2001 workbook package subsequently completed; the worker reports four completed jobs, six distinct extracted originals and 362,077 raw rows. The 1991 source validation count remains separately 118 sources and 697,205 data rows. Do not combine those measures as normalized observations.

On the next isolated server review, `extract_dchb_crosswalk.py` preserved 272 explicit 2001/2011 village-code pairs from PDF pages 83, 124, 150, 175, 201, 234 and 260. All 272 extracted 2011 codes are distinct. The parser requires the alphabetical-list title, both year headings, a district and a CD-block label on the same page, and complete six/eight-digit codes on each row. It retains leading zeroes, original line, page number, page-text checksum, PDF checksum and source URL. Tests reject incomplete codes and unrelated amenity headers. The output `source-evidence/dchb-2011-pilibhit-crosswalk-v1.jsonl` has SHA-256 `d4a8b48878a63850c7958d65aa22585f386fb388e096326db0aa3351e9503b06`.

These are unverified text-derived candidates, not accepted crosswalks; continuation pages without self-contained headings are deliberately excluded pending layout review. Next: review those continuation layouts and compare the printed pairs against preserved 2001/2011 Census and LGD code evidence before accepting any join. The three textless PDF pages are 2, 3 and 455, still pending visual review rather than automatically being called blank. PDF page 100 explicitly labels amenities and land use **as in 2009**, despite being in the 2011 DCHB; retain that observation-year distinction in future education/health/water extraction.
