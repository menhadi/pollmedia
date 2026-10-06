"""Extract ECI Hindi three-column roll parts using verified source metadata.

Retain every card, cache work by PDF checksum, and flag uncertain text.
Other layouts/languages require a separate verified adapter.
"""
import argparse
import concurrent.futures
import csv
import hashlib
import io
import json
import re
import subprocess
import threading
from pathlib import Path
import numpy as np
import pypdfium2 as pdfium
from PIL import Image
ROOT = Path(__file__).resolve().parent
PDF_RENDER_LOCK = threading.Lock()


def serial_at(context, page_number, cell):
    page = context.get("page_cards", {}).get(str(page_number))
    first = page["first_serial"] if page else (page_number-context["first_page"])*30+1
    return first+cell-1


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


def ocr_page(pdf_path, page_number, work, tessdata, context=None):
    context = context or dict(first_page=3, part=1, station="जूनियर हाई स्कूल फुलैया", printed_electors=933, sections={str(page_number):dict(number="1", name="फुलैया")})
    tsv = work/f"page-{page_number}.tsv"
    image_path = work/f"page-{page_number}.png"
    if not tsv.exists():
        # PDFium is not thread-safe; keep native rendering serialized while
        # independent recognition subprocesses can still run concurrently.
        with PDF_RENDER_LOCK:
            pdf = pdfium.PdfDocument(pdf_path)
            page = pdf[page_number-1]
            bitmap = page.render(scale=3)
            image = bitmap.to_pil().convert("RGB")
            bitmap.close()
            page.close()
            pdf.close()
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
            cell_number = row*3+col+1
            page_cards = context.get("page_cards", {}).get(str(page_number))
            if page_cards and cell_number > page_cards["count"]:
                continue
            expected = serial_at(context, page_number, cell_number)
            if expected > context["printed_electors"]:
                continue
            cell = [w for w in words if left <= w["x"] < left+420 and top-4 <= w["y"] < top+155]
            name_words = sorted([w for w in cell if top+43 <= w["y"] < top+75], key=lambda w:w["x"])
            relative_words = sorted([w for w in cell if top+75 <= w["y"] < top+105], key=lambda w:w["x"])
            name_text = " ".join(w["text"] for w in name_words)
            relative_text = " ".join(w["text"] for w in relative_words)
            name = re.sub(r"^.*?नाम\s*[:：]?\s*", "", name_text).strip()
            relative = re.search(r"(पिता|पति|माता)\s*का\s*नाम\s*[:：]?\s*(.+)", relative_text)
            field_words = [w for w in words if left <= w["x"] < left+560 and top <= w["y"] < top+160]
            detail_cache = work/f"fields-{page_number}-{row}-{col}.txt"
            if not detail_cache.exists():
                crop = image.crop((left+6, top+101, left+405, top+162)).resize((1197, 183))
                buffer = io.BytesIO()
                crop.save(buffer, format="PNG")
                text = subprocess.run(["tesseract", "stdin", "stdout", "--tessdata-dir", str(tessdata), "-l", "hin+eng", "--psm", "6"], input=buffer.getvalue(), capture_output=True, check=True).stdout.decode("utf-8")
                detail_cache.write_text(text, encoding="utf-8")
            detail_lines = detail_cache.read_text(encoding="utf-8").strip().splitlines()
            house_line = next((line for line in detail_lines if "मकान" in line or "संख्या" in line), "")
            age_line = next((line for line in detail_lines if "आयु" in line or "लिंग" in line), "")
            house = re.sub(r"^.*?(?:संख्या|सख्या)\s*[:：]?\s*", "", house_line).strip()
            age_match = re.search(r"(?:आयु|आय)\s*[:：]?\s*([0-9०-९]+)", age_line)
            gender_match = re.search(r"लिंग\s*[:：]?\s*(.+)", age_line)
            age = int(age_match[1]) if age_match else None
            gender = gender_match[1].strip() if gender_match else None
            epic_words = sorted([w for w in field_words if w["x"] >= left+420 and top <= w["y"] < top+40], key=lambda w:w["x"])
            epic = " ".join(w["text"] for w in epic_words).strip() or None
            details = dict(section_number=context["sections"][str(page_number)]["number"], section_name=context["sections"][str(page_number)]["name"], ward_number=None, house_number=house or None,
                           age=age if age is not None and 0 <= age <= 120 else None, age_text=age_line or None,
                           gender=gender, elector_id=epic, field_notes="Age, gender, house number and voter ID are OCR text; verify the original PDF.")
            serial_words = [w for w in cell if w["x"] < left+190 and top <= w["y"] < top+40]
            serial_match = re.search(r"(?<![0-9])([0-9]{1,3})(?![0-9])", "".join(w["text"] for w in serial_words))
            serial = int(serial_match[1]) if serial_match else None
            if not name_words and not relative_words:
                held.append(dict(pdf_page=page_number, cell=row*3+col+1, reason="Missing OCR text requires review", name="", relative_name="", relationship="Other", serial_verified=False, **details))
                continue
            if serial != expected:
                serial = serial_ocr(image, left, top, tessdata, work/f"serial-{page_number}-{row}-{col}.txt")
            value_words = [w for w in name_words if w["x"] >= left+60 and w["text"] not in [":", "："]]+[w for w in relative_words if w["x"] >= left+120 and w["text"] not in [":", "："]]
            confidence = min([w["conf"] for w in value_words] or [0])
            if serial != expected or not relative or not name or name == name_text or confidence < 70 or re.search(r"[A-Za-z]", name+relative[2]):
                held.append(dict(pdf_page=page_number, cell=row*3+col+1, reason="Name or relative name OCR is uncertain; verify PDF", name=name, relative_name=relative[2].strip() if relative else relative_text,
                                 relationship={"पिता":"Father", "पति":"Husband", "माता":"Mother"}[relative[1]] if relative else "Other", serial_verified=serial == expected, **details))
                continue
            records.append(dict(part=context["part"], station=context["station"], serial=serial, serial_verified=True, name=name, relative_name=relative[2].strip(), relationship={"पिता":"Father", "पति":"Husband", "माता":"Mother"}[relative[1]], pdf_page=page_number, extraction_status="ocr_candidate", **details))
    return records, held


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("pdf")
    parser.add_argument("--output", required=True)
    parser.add_argument("--workers", type=int, default=3)
    parser.add_argument("--reviews")
    parser.add_argument("--metadata", help="Source-verified metadata and page sections for the Hindi three-column ECI layout")
    args = parser.parse_args()
    pdf_path = Path(args.pdf)
    sha = hashlib.sha256(pdf_path.read_bytes()).hexdigest()
    if args.metadata:
        context = json.loads(Path(args.metadata).read_text(encoding="utf-8"))
        if context.get("pdf_sha256") != sha or context.get("layout") != "eci-hindi-three-column-v1" or context.get("roll_language") != "Hindi":
            raise ValueError("Source checksum or supported layout/language does not match")
        if context["printed_electors"] != sum(item["total"] for item in context["official_statistics"]):
            raise ValueError("Printed totals do not reconcile")
        for page in range(context["first_page"], context["last_page"]+1):
            if str(page) not in context["sections"]:
                raise ValueError("Every card page needs source-verified section metadata")
        if "page_cards" in context:
            next_serial = 1
            for page in range(context["first_page"], context["last_page"]+1):
                cards = context["page_cards"][str(page)]
                if cards["first_serial"] != next_serial or not 1 <= cards["count"] <= 30:
                    raise ValueError("Verified page card ranges must be contiguous")
                next_serial += cards["count"]
            if next_serial != context["printed_electors"]+1:
                raise ValueError("Verified page card ranges do not reconcile with printed totals")
    else:
        if sha != "a5e5374f0e051ae58301471262ca5bd815ab8880fdb62000c27008e70decdbac":
            raise ValueError("Supply verified --metadata for other official parts")
        context = dict(first_page=3, last_page=34, part=1, station="जूनियर हाई स्कूल फुलैया", printed_electors=933,
                       sections={str(page):dict(number="1", name="फुलैया") for page in range(3,35)})
    work = ROOT/"tmp"/"sir-draft-ocr"/sha if args.metadata else ROOT/"tmp"/"sir-draft-ocr"
    work.mkdir(parents=True, exist_ok=True)
    records, held = [], []
    with concurrent.futures.ThreadPoolExecutor(max_workers=args.workers) as pool:
        for extracted, rejected in pool.map(lambda n:ocr_page(pdf_path, n, work, ROOT/"tmp"/"sir-tessdata", context), range(context["first_page"], context["last_page"]+1)):
            records.extend(extracted)
            held.extend(rejected)
            print(f"processed {len(records)} candidates, {len(held)} held", flush=True)
    records.sort(key=lambda r:r["serial"])
    if len({r["serial"] for r in records}) != len(records) or len(records)+len(held) != context["printed_electors"]:
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
            serial = serial_at(context, cell["pdf_page"], cell["cell"])
            if serial not in reviewed:
                remaining.append(cell)
                continue
            item = reviewed.pop(serial)
            if len(item)!=4 or not item[1] or not item[2] or item[3] not in ["Father","Mother","Husband","Wife","Other"]:
                raise ValueError("Invalid reviewed record")
            details = {key:cell[key] for key in ["section_number", "section_name", "ward_number", "house_number", "age", "age_text", "gender", "elector_id", "field_notes"]}
            records.append(dict(part=context["part"], station=context["station"], serial=serial, serial_verified=True, name=item[1], relative_name=item[2], relationship=item[3], pdf_page=cell["pdf_page"], extraction_status="reviewed", **details))
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
    for cell in held:
        serial = serial_at(context, cell["pdf_page"], cell["cell"])
        details = {key:cell[key] for key in ["section_number", "section_name", "ward_number", "house_number", "age", "age_text", "gender", "elector_id", "field_notes", "serial_verified"]}
        records.append(dict(part=context["part"], station=context["station"], serial=serial,
                            name=cell["name"] or "[Unreadable name in OCR]", relative_name=cell["relative_name"] or "[Unreadable relative name in OCR]",
                            relationship=cell["relationship"], pdf_page=cell["pdf_page"], extraction_status="ocr_uncertain", extraction_note=cell["reason"], **details))
    uncertain_count = len(held)
    held = []
    records.sort(key=lambda r:r["serial"])
    if len(records) != context["printed_electors"] or {r["serial"] for r in records} != set(range(1, context["printed_electors"]+1)):
        raise ValueError("Every printed card must appear exactly once")
    if args.reviews:
        allowed_fields = {"house_number", "age", "gender", "elector_id", "serial_verified"}
        checked_serials = set()
        for checked in reviews.get("checked_details", []):
            serial = checked["serial"]
            if serial in checked_serials or set(checked)-{"serial"}-allowed_fields:
                raise ValueError("Invalid or duplicate field review")
            checked_serials.add(serial)
            record = next((r for r in records if r["serial"] == serial), None)
            if record is None:
                raise ValueError("Field review refers to a missing card")
            record.update({key:value for key,value in checked.items() if key in allowed_fields})
    result = dict(edition_key=sha, state_code="09", state_name="Uttar Pradesh", pc_code="26", pc_name="पीलीभीत / Pilibhit", pc_source_url="https://pilibhit.nic.in/meeting-blo-bla/", ac_code="127", ac_name="पीलीभीत / Pilibhit", year=2026, edition="SIR 2026 draft roll - published 6 January 2026", document_type="electoral_roll", document_date="2026-01-06", source_url="https://drive.google.com/file/d/14MYTjeyq4cEFetIEEhKN-_lMnuQCwY5q/view", source_landing_url="https://pilibhit.nic.in/meeting-blo-bla/", pdf_sha256=sha, records=records, held_rows=held, printed_electors=933, roll_language="Hindi", qualifying_date="2026-01-01", official_statistics=[dict(part=1, male=507, female=426, third_gender=0, total=933, pdf_page=35)])
    if args.metadata:
        for key in ["state_code", "state_name", "pc_code", "pc_name", "pc_source_url", "ac_code", "ac_name", "year", "edition", "document_date", "source_url", "source_landing_url", "roll_language", "qualifying_date", "printed_electors", "official_statistics"]:
            result[key] = context[key]
    result["visual_review_cells"] = review_count
    result["uncertain_records"] = uncertain_count
    output = Path(args.output)
    output.parent.mkdir(parents=True, exist_ok=True)
    output.write_text(json.dumps(result, ensure_ascii=False), encoding="utf-8")
    print(json.dumps(dict(records=len(records), held=len(held), sha256=hashlib.sha256(output.read_bytes()).hexdigest())), flush=True)
