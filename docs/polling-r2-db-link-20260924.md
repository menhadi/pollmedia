# Linking preserved polling PDFs in R2 to live records

The data-only PostgreSQL imports preserve document metadata, raw tables, rows and OCR evidence. They do not register R2 objects in `pdf_storage_files`. The source PDFs remain private in R2 under `pollmedia/polling-station-sources/`.

The 23 September upload has 30,563 verified, unique PDF receipts. All receipt source IDs, hashes, states and object keys match the preserved polling index. The index has 30,837 source documents; 274 are not PDFs. As of 24 September, the live database has 29,500 imported polling documents, with 1,337 Odisha PDF documents awaiting import while Odisha OCR runs. A pre-import database backup is at `/home/pollmedia/backups/polling-preimport-20260923T1705Z.dump`.

After the application code containing `polling:link-r2` is pulled on live, create and test a Cloudflare R2 profile at `/admin/pdf-storage`. Use bucket `pollmedia`, region `auto`, endpoint `https://9c3a111cd1c8101060111b18f9dda444.r2.cloudflarestorage.com`, and prefix `pollmedia`. Enter the R2 keys privately in the admin form; never place them in a shell command, Git, a receipt file or this document. The live database currently has no tested profile. The test button writes, reads and removes a temporary object before marking the profile tested.

The receipt file has been staged on the server at `/home/pollmedia/tmp/polling-r2-receipts.jsonl`. Its local and transferred SHA-256 both equal `e983075c3e66f91e7c90478a4e896de7ed67039631b8de535a54bc10c7e91586`. Verify that hash again before linking. Run:

```bash
cd /home/pollmedia/app
php8.4 application/artisan polling:link-r2 \
  --receipts=/home/pollmedia/tmp/polling-r2-receipts.jsonl \
  --profile=PROFILE_ID
```

Replace `PROFILE_ID` with the tested profile's numeric ID. The command checks each imported document's hash, state, file path, object key, bucket and endpoint against its receipt. It links matching PDFs without reimporting pages, is safe to rerun, rolls back on a conflict, and reports receipts whose documents have not yet been imported. Rerun it after Odisha is imported. Do not claim live PDF access until a tested profile and this link pass are both verified.
