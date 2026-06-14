#!/usr/bin/env python3
"""
Import QuickBooks customer export (.xlsx) into OGM customers/ JSON files.
Usage:
  python3 scripts/import_quickbooks_customers.py "/path/to/Customer Name List.xlsx"
"""

from __future__ import annotations

import json
import random
import re
import string
import sys
import time
import zipfile
import xml.etree.ElementTree as ET
from datetime import datetime
from pathlib import Path

NS = {"m": "http://schemas.openxmlformats.org/spreadsheetml/2006/main"}
ROOT = Path(__file__).resolve().parent.parent
CUSTOMERS_DIR = ROOT / "customers"
DEFAULT_XLSX = "/Volumes/Shared Files/Tanya/Customer Name List.xlsx"


def today_str() -> str:
    d = datetime.now()
    day = str(d.day)
    return d.strftime(f"%B {day}, %Y")


def gen_id() -> str:
    ts = format(int(time.time() * 1000), "x").upper()
    suffix = "".join(random.choices(string.ascii_uppercase + string.digits, k=3))
    return f"C{ts}{suffix}"


def norm_key(s: str) -> str:
    return re.sub(r"\s+", " ", str(s or "").strip().lower())


def phone_digits(s: str) -> str:
    return re.sub(r"\D", "", s or "")


def col_row(ref: str) -> tuple[str, int]:
    m = re.match(r"([A-Z]+)(\d+)", ref)
    if not m:
        return "", 0
    return m.group(1), int(m.group(2))


def load_shared_strings(z: zipfile.ZipFile) -> list[str]:
    ss: list[str] = []
    if "xl/sharedStrings.xml" not in z.namelist():
        return ss
    root = ET.fromstring(z.read("xl/sharedStrings.xml"))
    for si in root.findall(".//m:si", NS):
        texts = [t.text or "" for t in si.findall(".//m:t", NS)]
        ss.append("".join(texts))
    return ss


def sheet_to_table(z: zipfile.ZipFile, sheet_path: str, ss: list[str]) -> list[dict[str, str]]:
    sheet = ET.fromstring(z.read(sheet_path))
    rows: dict[int, dict[str, str]] = {}
    for c in sheet.findall(".//m:c", NS):
        ref = c.get("r", "")
        col, row = col_row(ref)
        if not row:
            continue
        t = c.get("t")
        v = c.find("m:v", NS)
        val = v.text if v is not None else ""
        if t == "s" and val.isdigit():
            val = ss[int(val)]
        rows.setdefault(row, {})[col] = str(val or "").strip()

    if 1 not in rows:
        return []

    header_row = rows[1]
    cols = sorted(header_row.keys(), key=lambda x: (len(x), x))
    headers = [header_row[c] for c in cols]
    out: list[dict[str, str]] = []
    for r in sorted(rows.keys()):
        if r == 1:
            continue
        obj: dict[str, str] = {}
        any_val = False
        for c, h in zip(cols, headers):
            hk = norm_key(h)
            if not hk:
                continue
            v = rows[r].get(c, "").strip()
            if v:
                any_val = True
            obj[hk] = v
        if any_val:
            out.append(obj)
    return out


def pick(row: dict[str, str], *aliases: str) -> str:
    for a in aliases:
        v = row.get(norm_key(a), "")
        if v:
            return v.strip()
    return ""


def qb_status(active: str) -> str:
    k = (active or "").lower().strip()
    if k == "active":
        return "active"
    if k in ("not-active", "inactive", "not active"):
        return "prospect"
    return "prospect"


def addr_from_block(row: dict[str, str], prefix: str) -> tuple[str, str]:
    street = pick(row, f"{prefix} 2", f"{prefix}2")
    city_line = pick(row, f"{prefix} 3", f"{prefix}3")
    if not street and not city_line:
        line2 = pick(row, f"{prefix} 1", f"{prefix}1")
        if line2 and not pick(row, f"{prefix} 2"):
            return line2, ""
    return street, city_line


def customer_from_qb_row(row: dict[str, str]) -> dict | None:
    first = pick(row, "first name", "firstname")
    last = pick(row, "last name", "lastname")
    customer = pick(row, "customer")
    company = pick(row, "company")
    phone = pick(row, "main phone", "phone", "primary phone")
    phone2 = pick(row, "alt. phone", "alt phone", "alternate phone")
    email = pick(row, "main email", "email", "e-mail")

    if not last:
        last = customer or company
    if not first and not last and not phone and not email:
        return None

    ship_street, ship_city = addr_from_block(row, "ship to")
    bill_street, bill_city = addr_from_block(row, "bill to")
    svc_street = ship_street or bill_street
    svc_city = ship_city or bill_city
    same_addr = not ship_street and not ship_city
    if same_addr:
        bill_street = svc_street
        bill_city = svc_city

    rep = pick(row, "rep", "salesperson")
    active = pick(row, "active status")

    return {
        "firstName": first,
        "lastName": last,
        "phone": phone,
        "phone2": phone2,
        "email": email,
        "email2": pick(row, "secondary contact"),
        "svcStreet": svc_street,
        "svcCity": svc_city,
        "billStreet": bill_street,
        "billCity": bill_city,
        "sameAddr": same_addr,
        "jobName": pick(row, "job description", "job type"),
        "status": qb_status(active),
        "rep": rep or "Tanya Wadkins (TW)",
        "source": "QuickBooks",
        "referral": "",
        "notes": "",
        "_qbCustomer": customer,
        "_qbCompany": company,
    }


def load_existing(customers_dir: Path) -> list[dict]:
    existing: list[dict] = []
    for path in customers_dir.glob("*.json"):
        if path.name.startswith("_"):
            continue
        try:
            data = json.loads(path.read_text(encoding="utf-8"))
            if isinstance(data, dict) and data.get("id"):
                existing.append(data)
        except (json.JSONDecodeError, OSError):
            pass
    return existing


def find_match(existing: list[dict], incoming: dict) -> dict | None:
    in_phone = phone_digits(incoming.get("phone", ""))
    in_email = (incoming.get("email") or "").lower().strip()
    in_name = " ".join(
        filter(None, [(incoming.get("lastName") or "").lower(), (incoming.get("firstName") or "").lower()])
    ).strip()
    qb_name = (incoming.get("_qbCustomer") or incoming.get("_qbCompany") or "").lower().strip()

    for c in existing:
        if in_phone and phone_digits(c.get("phone", "")) == in_phone:
            return c
        if in_phone and phone_digits(c.get("phone2", "")) == in_phone:
            return c
        ce = (c.get("email") or "").lower().strip()
        if in_email and ce and in_email == ce:
            return c
        cname = " ".join(
            filter(None, [(c.get("lastName") or "").lower(), (c.get("firstName") or "").lower()])
        ).strip()
        if in_name and cname and in_name == cname:
            return c
        if qb_name and (c.get("lastName") or "").lower().strip() == qb_name:
            return c
    return None


def merge_fields(existing: dict, incoming: dict) -> None:
    keys = [
        "firstName", "lastName", "phone", "phone2", "email", "email2",
        "svcStreet", "svcCity", "billStreet", "billCity", "jobName",
        "status", "rep", "source", "referral", "notes",
    ]
    for k in keys:
        v = incoming.get(k)
        if v is not None and str(v).strip():
            existing[k] = v
    if incoming.get("sameAddr"):
        existing["sameAddr"] = True
        existing["billStreet"] = existing.get("svcStreet", "")
        existing["billCity"] = existing.get("svcCity", "")
    existing["updatedAt"] = today_str()
    if not existing.get("source"):
        existing["source"] = "QuickBooks"


def write_customer(customers_dir: Path, customer: dict) -> None:
    path = customers_dir / f"{customer['id']}.json"
    path.write_text(json.dumps(customer, indent=4, ensure_ascii=False) + "\n", encoding="utf-8")


def find_qb_sheet(z: zipfile.ZipFile, ss: list[str]) -> str:
    best_path = "xl/worksheets/sheet1.xml"
    best_rows = 0
    for name in z.namelist():
        if not name.startswith("xl/worksheets/sheet") or not name.endswith(".xml"):
            continue
        sheet = ET.fromstring(z.read(name))
        row_nums = set()
        has_customer_hdr = False
        for c in sheet.findall(".//m:c", NS):
            ref = c.get("r", "")
            _, row = col_row(ref)
            if row:
                row_nums.add(row)
            t = c.get("t")
            v = c.find("m:v", NS)
            val = v.text if v is not None else ""
            if t == "s" and val.isdigit():
                val = ss[int(val)]
            if row == 1 and str(val).strip().lower() == "customer":
                has_customer_hdr = True
        count = len(row_nums)
        if has_customer_hdr and count > best_rows:
            best_rows = count
            best_path = name
    return best_path


def main() -> int:
    xlsx_path = Path(sys.argv[1]) if len(sys.argv) > 1 else Path(DEFAULT_XLSX)
    if not xlsx_path.is_file():
        print(f"File not found: {xlsx_path}", file=sys.stderr)
        return 1

    CUSTOMERS_DIR.mkdir(parents=True, exist_ok=True)
    existing = load_existing(CUSTOMERS_DIR)

    with zipfile.ZipFile(xlsx_path) as z:
        ss = load_shared_strings(z)
        sheet_path = find_qb_sheet(z, ss)
        rows = sheet_to_table(z, sheet_path, ss)

    print(f"Reading {xlsx_path.name} ({len(rows)} rows from {sheet_path})")

    added = updated = skipped = 0
    for row in rows:
        incoming = customer_from_qb_row(row)
        if not incoming:
            skipped += 1
            continue

        match = find_match(existing, incoming)
        if match:
            merge_fields(match, incoming)
            write_customer(CUSTOMERS_DIR, match)
            updated += 1
            continue

        cid = gen_id()
        customer = {
            "id": cid,
            "firstName": incoming.get("firstName", ""),
            "lastName": incoming.get("lastName", ""),
            "phone": incoming.get("phone", ""),
            "phone2": incoming.get("phone2", ""),
            "email": incoming.get("email", ""),
            "email2": incoming.get("email2", ""),
            "svcStreet": incoming.get("svcStreet", ""),
            "svcCity": incoming.get("svcCity", ""),
            "sameAddr": incoming.get("sameAddr", True),
            "billStreet": incoming.get("billStreet", ""),
            "billCity": incoming.get("billCity", ""),
            "jobName": incoming.get("jobName", ""),
            "status": incoming.get("status", "prospect"),
            "rep": incoming.get("rep", "Tanya Wadkins (TW)"),
            "source": "QuickBooks",
            "referral": "",
            "notes": "",
            "createdAt": today_str(),
            "updatedAt": today_str(),
            "quotes": [],
            "notesLog": [],
        }
        if customer["sameAddr"]:
            customer["billStreet"] = customer["svcStreet"]
            customer["billCity"] = customer["svcCity"]

        existing.append(customer)
        write_customer(CUSTOMERS_DIR, customer)
        added += 1

    print(f"Done: {added} added, {updated} updated, {skipped} skipped (no contact/name).")
    print(f"Customer files in: {CUSTOMERS_DIR}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
