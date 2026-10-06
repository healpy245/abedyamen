#!/usr/bin/env python3
from __future__ import annotations

import json
import urllib.parse
from pathlib import Path

from _find_visa_292 import get_json, login, slim_log

OUT = Path(__file__).resolve().parent / "_c38e_create_trail.json"


def pull(q: str, frm: str = "", to: str = "", source: str = "") -> list:
    params = {
        "restaurant": "gcp",
        "view": "all",
        "q": q,
    }
    if frm:
        params["from"] = frm
    if to:
        params["to"] = to
    if source:
        params["source"] = source
    path = "/kaman-support/live/feed?" + urllib.parse.urlencode(params)
    try:
        data = get_json(path)
        return [slim_log(row) for row in (data.get("logs") or [])]
    except Exception as exc:
        return [{"error": str(exc), "path": path}]


def main() -> None:
    login()
    feeds = {
        "create_13_40": pull("POST /orders", "2026-09-21 13:38", "2026-09-21 13:45"),
        "create_body_13_40": pull("body={\"order_method\"", "2026-09-21 13:38", "2026-09-21 13:45"),
        "items_13_40": pull("/items", "2026-09-21 13:38", "2026-09-21 13:50"),
        "delete_items": pull("DELETE", "2026-09-21 13:38", "2026-09-21 14:30"),
        "taps_create": pull("tapped", "2026-09-21 13:38", "2026-09-21 13:50", source="flutter"),
        "taps_visa": pull("tapped", "2026-09-21 14:24", "2026-09-21 14:29", source="flutter"),
        "c38e_window": pull("01a0c38e", "2026-09-21 13:38", "2026-09-21 14:30"),
        "c385_create": pull("01a0c385", "2026-09-21 14:10", "2026-09-21 14:20"),
        "visa_14_18": pull("pay/visa", "2026-09-21 14:16", "2026-09-21 14:20"),
        "visa_14_25": pull("pay/visa", "2026-09-21 14:24", "2026-09-21 14:30"),
        "put_c38e": pull("PUT /orders", "2026-09-21 13:38", "2026-09-21 14:30"),
    }
    OUT.write_text(json.dumps(feeds, ensure_ascii=False, indent=2), encoding="utf-8")
    print("WROTE", OUT)


if __name__ == "__main__":
    main()
