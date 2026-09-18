"""Map ECI's state/year rows without assuming current state boundaries."""
import argparse
from datetime import datetime, timezone
import hashlib
import json
from pathlib import Path
import re
from urllib.parse import urljoin, urlparse
from bs4 import BeautifulSoup
from collect_election_archive import fetch

URL = "https://www.eci.gov.in/eci-backend/public/api/get-election-data?page_seo_name=statistical-reports"


def parse(body):
    soup = BeautifulSoup(json.loads(body)["cmsPagesData"]["page_content"], "html.parser")
    entries = []
    for row in soup.select("tr"):
        cells = row.find_all("td", recursive=False)
        if not cells:
            continue
        state = cells[0].get_text(" ", strip=True)
        if not state or re.search(r"\d", state):
            continue
        for anchor in row.select("a[href]"):
            label = anchor.get_text(" ", strip=True)
            url = urljoin("https://www.eci.gov.in/", anchor["href"])
            if re.fullmatch(r"(?:19|20)\d{2}(?:\s*\(.*\))?", label):
                if urlparse(url).scheme != "https" or urlparse(url).hostname not in ["www.eci.gov.in", "old.eci.gov.in"]:
                    raise ValueError("Non-official report URL")
                entries.append({"state": state, "year": int(label[:4]), "label": label, "url": url})
    if not entries or len({(e["state"], e["year"], e["url"]) for e in entries}) != len(entries):
        raise ValueError("Empty or duplicate catalogue")
    return {"source_url": URL, "landing_url": "https://www.eci.gov.in/statistical-reports", "retrieved_at": datetime.now(timezone.utc).isoformat(), "sha256": hashlib.sha256(body).hexdigest(), "entries": entries}


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    parser.add_argument("output", type=Path)
    parser.add_argument("--saved", type=Path)
    args = parser.parse_args()
    raw = args.saved.read_bytes() if args.saved else fetch(URL, args.output.with_suffix(".source.json"))
    data = parse(raw)
    args.output.write_text(json.dumps(data, indent=2) + "\n", encoding="utf-8")
    print(str(len(data["entries"])) + " official state/year editions")
