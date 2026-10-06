#!/usr/bin/env python3
from __future__ import annotations

import json
import re
import urllib.parse
from pathlib import Path

from _audit_gcp_live import csrf, fetch
from _find_visa_292 import get_json, login, slim_log

OUT = Path(__file__).resolve().parent / "_visa_order_trail.json"

QUERIES = [
    "01a0c3b7",
    "01a0c3ba",
    "01a0c38e",
    "منتج غير مدرج",
    "item_price",
    "unlisted",
]


def main() -> None:
    login()
    feeds = {}
    for q in QUERIES:
        path = "/kaman-support/live/feed?restaurant=gcp&view=all&q=" + urllib.parse.quote(q)
        try:
            data = get_json(path)
            feeds[q] = [slim_log(row) for row in (data.get("logs") or [])]
        except Exception as exc:
            feeds[q] = [{"error": str(exc)}]

    # Cashier tablet 14, all logs around the payment (no q — last 120).
    try:
        raw = get_json("/kaman-support/live/14/feed?view=all")
        feeds["device14_all"] = [slim_log(row) for row in (raw.get("logs") or [])]
    except Exception as exc:
        feeds["device14_all"] = [{"error": str(exc)}]

    OUT.write_text(json.dumps(feeds, ensure_ascii=False, indent=2), encoding="utf-8")
    print("WROTE", OUT, {k: len(v) if isinstance(v, list) else v for k, v in feeds.items()})


if __name__ == "__main__":
    main()
