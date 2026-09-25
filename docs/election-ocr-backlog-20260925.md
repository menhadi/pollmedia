# Election OCR queue snapshot — 25 September 2026

A read-only scan applied the current `ocr_candidates` and `result_source` rules to the preserved per-source table indexes for the 11 states in the two scheduled OCR lanes. It did not read full page JSON, render PDFs, or change OCR output. Page counts are OCR-eligible source pages, not unique elections, polling stations, or nationally complete coverage. State-directory labels are collection paths and may not be verified jurisdictions.

| Lane | State label | Eligible pages | Indexed OCR pages | Remaining pages |
| --- | --- | ---: | ---: | ---: |
| Primary | Tamil Nadu | 636 | 636 | 0 |
| Primary | Assam | 141 | 141 | 0 |
| Primary | Odisha | 2,068 | 2,068 | 0 |
| Primary | Andhra Pradesh | 2,925 | 2,925 | 0 |
| Primary | West Bengal | 9,275 | 7,416 | 1,859 |
| Primary | Madhya Pradesh | 29,861 | 646 | 29,215 |
| Secondary | Haryana | 79 | 79 | 0 |
| Secondary | Chhattisgarh | 2,082 | 2,082 | 0 |
| Secondary | Uttarakhand | 2,347 | 500 | 1,847 |
| Secondary | Bihar | 9,927 | 4,568 | 5,359 |
| Secondary | NCT of Delhi | 28,375 | 357 | 28,018 |
| **Total** | | **87,716** | **21,418** | **66,298** |

These are moving counts while scheduled workers continue. The scan counted page entries in existing OCR indexes; it did not rehash each OCR page file. It found no missing table index for these configured queues. It excludes non-result documents and pages with text that do not require OCR, and is narrower than the 382,464 source pages in the polling database. Other preserved election archives, external source gaps, and manual verification remain separate work.

Recent West Bengal batches usually completed about 500 pages per hour, with one slower batch during low-memory pressure. At a conservative 300–500 OCR pages per worker-hour, the 66,298-page queue represents roughly 130–220 worker-hours. Actual calendar time depends on RAM gating and how long the PC stays awake; about one to two weeks of continuous one-worker operation, or several weeks if intermittent, is a planning range rather than a deadline. OCR alone preserves unverified words and coordinates. It does not automatically add defensible vote rows or resolve historical source gaps.
