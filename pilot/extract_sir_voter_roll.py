"""OCR the reviewed Hindi Pilibhit SIR Part 1 layout; hold uncertain cells."""
import argparse
import concurrent.futures
import csv
import hashlib
import io
import json
import re
import subprocess
from pathlib import Path
import numpy as np
import pypdfium2 as pdfium
from PIL import Image
ROOT = Path(__file__).resolve().parent


def serial_ocr(image, left, top, tessdata, cache):
    if cache.exists():
        value = cache.read_text(encoding="ascii").strip()
    else:
        crop = image.crop((left+10, top+11, left+190, top+37)).resize((720, 104))
        buffer = io.BytesIO()
        crop.save(buffer, format="PNG")
        value = subprocess.run(["tesseract", "stdin", "stdout", "--tessdata-dir", str(tessdata), "-l", "eng", "--psm", "7", "-c", "tessedit_char_whitelist=0123456789"], input=buffer.getvalue(), capture_output=True, check=True).stdout.decode().strip()
        cache.write_text(value, encoding="ascii")
    return int(value) if value.isdigit() else None


def ocr_page(pdf_path, page_number, work, tessdata):
    tsv = work/f"page-{page_number}.tsv"
    image_path = work/f"page-{page_number}.png"
    if not tsv.exists():
        pdf = pdfium.PdfDocument(pdf_path)
        image = pdf[page_number-1].render(scale=3).to_pil().convert("RGB")
        image.save(image_path)
        subprocess.run(["tesseract", str(image_path), str(work/f"page-{page_number}"), "--tessdata-dir", str(tessdata), "-l", "hin+eng", "--psm", "11", "-c", "tessedit_create_tsv=1"], check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    with tsv.open(encoding="utf-8") as stream:
        words = [dict(text=r["text"], x=int(r["left"]), y=int(r["top"]), conf=float(r["conf"])) for r in csv.DictReader(stream, delimiter="\t") if r["text"].strip()]
    image = Image.open(image_path).convert("RGB")
    pixels = np.asarray(image.convert("L"))
    lines = np.where((pixels[:, 42:594] < 130).mean(axis=1) > .85)[0]
    groups = []
    for y in lines:
        if not groups or y > groups[-1][-1]+1:
            groups.append([int(y)])
        else:
            groups[-1].append(int(y))
    if len(groups) % 2:
        raise ValueError(f"Unrecognized card borders on page {page_number}")
    records, held = [], []
    for row in range(len(groups)//2):
        top = groups[row*2][-1]
        for col in range(3):
            left = 40+col*571
            expected = (page_number-3)*30+row*3+col+1
            cell = [w for w in words if left <= w["x"] < left+420 and top-4 <= w["y"] < top+155]
            name_words = sorted([w for w in cell if top+43 <= w["y"] < top+75], key=lambda w:w["x"])
            relative_words = sorted([w for w in cell if top+75 <= w["y"] < top+105], key=lambda w:w["x"])
            name_text = " ".join(w["text"] for w in name_words)
            relative_text = " ".join(w["text"] for w in relative_words)
            name = re.sub(r"^.*?नाम\s*[:：]?\s*", "", name_text).strip()
            relative = re.search(r"(पिता|पति|माता)\s*का\s*नाम\s*[:：]?\s*(.+)", relative_text)
            serial_words = [w for w in cell if w["x"] < left+190 and top <= w["y"] < top+40]
            serial_match = re.search(r"(?<![0-9])([0-9]{1,3})(?![0-9])", "".join(w["text"] for w in serial_words))
            serial = int(serial_match[1]) if serial_match else None
            if not name_words and not relative_words:
                held.append(dict(pdf_page=page_number, cell=row*3+col+1, reason="Missing OCR text requires review"))
                continue
            if serial != expected:
                serial = serial_ocr(image, left, top, tessdata, work/f"serial-{page_number}-{row}-{col}.txt")
            value_words = [w for w in name_words if w["x"] >= left+60 and w["text"] not in [":", "："]]+[w for w in relative_words if w["x"] >= left+120 and w["text"] not in [":", "："]]
            confidence = min([w["conf"] for w in value_words] or [0])
            if serial != expected or not relative or not name or name == name_text or confidence < 70 or re.search(r"[A-Za-z]", name+relative[2]):
                held.append(dict(pdf_page=page_number, cell=row*3+col+1, reason="OCR requires review"))
                continue
            records.append(dict(part=1, station="जूनियर हाई स्कूल फुलैया", serial=serial, name=name, relative_name=relative[2].strip(), relationship={"पिता":"Father", "पति":"Husband", "माता":"Mother"}[relative[1]], pdf_page=page_number, extraction_status="ocr_candidate"))
    return records, held


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("pdf")
    parser.add_argument("--output", required=True)
    parser.add_argument("--workers", type=int, default=3)
    parser.add_argument("--reviews")
    args = parser.parse_args()
    pdf_path = Path(args.pdf)
    sha = hashlib.sha256(pdf_path.read_bytes()).hexdigest()
    if sha != "a5e5374f0e051ae58301471262ca5bd815ab8880fdb62000c27008e70decdbac":
        raise ValueError("This reviewed parser supports only the acquired Pilibhit Part 1 draft PDF")
    work = ROOT/"tmp"/"sir-draft-ocr"
    work.mkdir(parents=True, exist_ok=True)
    records, held = [], []
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
        for extracted, rejected in pool.map(lambda n:ocr_page(pdf_path, n, work, ROOT/"tmp"/"sir-tessdata"), range(3, 35)):
            records.extend(extracted)
            held.extend(rejected)
            print(f"processed {len(records)} candidates, {len(held)} held", flush=True)
    records.sort(key=lambda r:r["serial"])
    if len({r["serial"] for r in records}) != len(records) or len(records)+len(held) != 933:
        raise ValueError("Card count does not reconcile with printed elector total")
    review_count = 0
    if args.reviews:
        reviews = json.loads(Path(args.reviews).read_text(encoding="utf-8"))
        if reviews.get("pdf_sha256") != sha:
            raise ValueError("Visual review belongs to a different PDF")
        reviewed = {item[0]:item for item in reviews["cells"]}
        if len(reviewed) != len(reviews["cells"]):
            raise ValueError("Duplicate visual review serial")
        remaining = []
        for cell in held:
            serial = (cell["pdf_page"]-3)*30+cell["cell"]
            if serial not in reviewed:
                remaining.append(cell)
                continue
            item = reviewed.pop(serial)
            if len(item)!=4 or not item[1] or not item[2] or item[3] not in ["Father","Mother","Husband","Wife","Other"]:
                raise ValueError("Invalid reviewed record")
            records.append(dict(part=1, station="जूनियर हाई स्कूल फुलैया", serial=serial, name=item[1], relative_name=item[2], relationship=item[3], pdf_page=cell["pdf_page"], extraction_status="reviewed"))
            review_count += 1
        if reviewed:
            raise ValueError("Reviewed serial is not in the held inventory")
        held = remaining
        records.sort(key=lambda r:r["serial"])
        for checked in reviews.get("checked_candidates", []):
            record = next((r for r in records if r["serial"] == checked[0]), None)
            if record is None or (record["name"], record["relative_name"]) != (checked[1], checked[2]):
                raise ValueError("Checked candidate differs from the source review")
            record["extraction_status"] = "reviewed"
    result = dict(edition_key=sha, state_code="09", state_name="Uttar Pradesh", pc_code="26", pc_name="पीलीभीत / Pilibhit", pc_source_url="https://pilibhit.nic.in/meeting-blo-bla/", ac_code="127", ac_name="पीलीभीत / Pilibhit", year=2026, edition="SIR 2026 draft roll - published 6 January 2026", document_type="electoral_roll", document_date="2026-01-06", source_url="https://drive.google.com/file/d/14MYTjeyq4cEFetIEEhKN-_lMnuQCwY5q/view", source_landing_url="https://pilibhit.nic.in/meeting-blo-bla/", pdf_sha256=sha, records=records, held_rows=held, printed_electors=933, roll_language="Hindi", qualifying_date="2026-01-01", official_statistics=[dict(part=1, male=507, female=426, third_gender=0, total=933, pdf_page=35)])
    result["visual_review_cells"] = review_count
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(result, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(dict(records=len(records), held=len(held), sha256=hashlib.sha256(output.read_bytes()).hexdigest())), flush=True)
