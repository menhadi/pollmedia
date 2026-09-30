# 2009 Lok Sabha State/UT visibility correction — 30 September 2026

The official [ECI 2009 Lok Sabha report](https://old.eci.gov.in/files/category/98-general-election-2009/) was already preserved and extracted: **543 parliamentary constituency tables, 8,070 candidate rows**. The live national constituency finder returned 543 PC records for 2009 before this correction; seven 2009 Assembly editions separately returned 992 AC records. The problem was narrower: the PC records carried source codes such as `S24` without a `state_name`, while state election dashboards select records by the printed State/UT name. Thus the 2009 Lok Sabha edition was absent from those state histories. No second copy of the election is needed.

The report's preserved *Constituency Wise Detailed Result.pdf* (`5556fc48df7b5645106ff974-6640.pdf`, SHA-256 `0689cff95684502c78b00a751184fc08a74fda9dd8ea33aef2aeb87ba20ae6a2`) prints a State/UT heading before each of its 35 groups. The extractor now pairs each heading with the next official PC 1 and the existing source State/UT code, and retains the heading page. For example, `S01` is printed under `Andhra Pradesh` on PDF page 1, `S07` under `Haryana` on page 46, `S20` under `Rajasthan` on page 113, and `S24` under `Uttar Pradesh` on page 140. These are historical source labels, not present-day boundary assertions.

Only `state_name`, `state_heading_page`, and the human-readable `name` changed in the 543 records. Source code, PC code, candidate names, votes, original files, hashes, source URL, raw tables, review status and warnings are unchanged. The 2009 edition still has **31 validated** and **512 needs-review** records; the latter are not promoted to accepted election contests. Across all PC/AC editions the expected index remains **74,218 records in 448 editions**.

The exact previous extraction is preserved as `extraction-25c10ce6274f98fe77cc3079038235bcce8186f955a421b43301e65a44f30c39.json`. The new extraction SHA-256 is `dbf599b4be85e2cdc21bdfe6e361b207fe9ea4cae90f9562d28e5fbd9aef3863`. The data-only bundle is `exports/pollmedia-pc-2009-state-correction-bundle-20260930.zip` (526,005 bytes, SHA-256 `584e7aa6e98da192613c04c4296db3319e136ec4bda91b8af975bdb0382387ae`). It contains the old snapshot and guarded revision with an import script. No PDF or R2 upload is needed.

## Live import

On the server, first ensure `/home/pollmedia/tmp` exists. From Windows PowerShell, upload the bundle:

```powershell
scp "D:\pollmedia\exports\pollmedia-pc-2009-state-correction-bundle-20260930.zip" root@mail.pollmedia.org:/home/pollmedia/tmp/
```

Then, in the server terminal, with no other election import active:

```bash
cd /home/pollmedia/tmp
echo '584e7aa6e98da192613c04c4296db3319e136ec4bda91b8af975bdb0382387ae  pollmedia-pc-2009-state-correction-bundle-20260930.zip' | sha256sum -c -
mkdir -p pc-2009-state-correction-20260930
unzip -n pollmedia-pc-2009-state-correction-bundle-20260930.zip -d pc-2009-state-correction-20260930
cd pc-2009-state-correction-20260930
bash IMPORT.sh
```

The script verifies both inner checksums, preserves the exact previous JSON in the database, requires at least 10 GiB free server disk, checks the prior SHA before replacing it, verifies 74,218/448, and rebuilds the constituency index. It makes no code pull or migration. After import, check the 2009 PC edition and a state history such as Uttar Pradesh. The count should remain 543 for 2009 PC because this is an identity correction, not new election data.
