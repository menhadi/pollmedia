# Census server staging checkpoint — 25 September 2026

The 1991 Primary Census Abstract workbooks are staged for review on the server without changing the live application directory or PostgreSQL database. This is historical source evidence, not a normalized or published national Census series.

- Private staging directory: `/home/pollmedia/tmp/census-1991-20260925/`.
- Prepared source-table bundle: `pollmedia-census-public-tables-20260918.zip`, SHA-256 `1ca3fd675cd153e75089137c60736a59e041c9cfb7fea4f0a7537afbe296c16c`. Its 237 source files passed individual SHA-256 checks; 118 workbooks are indexed, with zero pending entries in this saved 1991 catalogue.
- Original workbook/source bundle: `pollmedia-census-1991-pca-complete-20260918.zip`, SHA-256 `79ae5f1d951925bec0db9d185b851baaa62adbe2ad08d7dcf95ac7ccad154ed5`. All 477 archived files passed their internal SHA-256 checks; the saved summary reports 118 collected workbooks.
- Isolated review database: `census-1991-review.sqlite`, SHA-256 `d7e223e1ed4d4bf97bd1e638b08501656ee087c5914a706aeaa914531fcef99d`. It contains 118 source records, 236 worksheets and 697,205 original data rows. `PRAGMA integrity_check` returned `ok`, and `PRAGMA foreign_key_check` returned zero violations. Source URLs, original and extraction hashes, source row numbers, raw cell values, formulas/errors and page hashes are retained.
- The source collector reports 697,441 worksheet rows including headers and notes. The review database indexes the 697,205 data rows from the prepared source pages. These are source rows, not deduplicated people or current population estimates.

The server had about 102 GiB free after staging, above the 10 GiB reserve. Work ran at low CPU and I/O priority in a private temporary directory. The live `application/storage/app/private/census-source-tables` directory remained absent, and no census schema migration, PostgreSQL import or public-site publication was run. The original files remain on the local PC as well.

The standalone checksum verifier and staging builder are in `pilot/install_census_source_bundle.py` and `pilot/build_census_staging_db.py`. Their tests and the actual prepared bundle check passed. A later live move needs a reviewed import/export path from this isolated SQLite database into the intended PostgreSQL schema, reconciliation of geography and overlapping population groups, a fresh backup, and a separate live release decision. Copying the SQLite file into the live database is not a migration.
