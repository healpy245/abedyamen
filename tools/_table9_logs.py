#!/usr/bin/env python3
"""Pull GCP table-9 logs. Temporary. Do not commit."""
from __future__ import annotations

import json
import urllib.parse
from datetime import datetime, timedelta
from pathlib import Path

from _find_visa_292 import get_json, login, slim_log

OUT = Path(__file__).resolve().parent / "_table9_logs.json"
NOW = datetime(2026, 9, 21, 22, 40)
START = NOW - timedelta(hours=6)


def windows():
    t = START
    while t < NOW:
        nxt = min(t + timedelta(minutes=30), NOW)
        yield t.strftime("%Y-%m-%d %H:%M:%S"), nxt.strftime("%Y-%m-%d %H:%M:%S")
        t = nxt


def pull(path: str) -> dict:
    try:
        return get_json(path)
    except Exception as exc:
        return {"error": f"{type(exc).__name__}: {exc}", "logs": []}


def main() -> None:
    login()
    queries = [
        "طاولة 9",
        "طاولة9",
        "table 9",
        "Table 9",
        "table_id",
        "طلبية",
    ]
    hits = {}
    for q in queries:
        path = "/kaman-support/live/feed?" + urllib.parse.urlencode(
            {"restaurant": "gcp", "view": "all", "q": q, "from": START.strftime("%Y-%m-%d %H:%M:%S"), "to": NOW.strftime("%Y-%m-%d %H:%M:%S")}
        )
        data = pull(path)
        logs = data.get("logs") or []
        hits[q] = {
            "returned": len(logs),
            "error": data.get("error"),
            "logs": [slim_log(row) for row in logs[:40]],
        }
        print(repr(q), len(logs), data.get("error"))

    # Also scan all gcp logs in 30-min windows and keep lines that mention 9 / items / orders
    interesting = []
    seen = set()
    needles = ("طاولة", "table", "/orders", "/items", "item", "duplicate", "qty", "quantity")
    for a, b in windows():
        path = "/kaman-support/live/feed?" + urllib.parse.urlencode(
            {"restaurant": "gcp", "view": "all", "from": a, "to": b}
        )
        data = pull(path)
        logs = data.get("logs") or []
        kept = 0
        for row in logs:
            rid = row.get("id")
            if rid in seen:
                continue
            msg = row.get("message") or ""
            if not any(n.lower() in msg.lower() for n in needles):
                continue
            # prefer table 9 signals or order mutations
            low = msg.lower()
            if not any(s in low for s in ("9", "طاولة", "/orders", "/items", "table")):
                continue
            seen.add(rid)
            interesting.append(slim_log(row))
            kept += 1
        print(a, "returned", len(logs), "kept", kept)

    payload = {"queries": {k: {"returned": v["returned"], "error": v["error"], "sample": v["logs"][:8]} for k, v in hits.items()}, "interesting_count": len(interesting), "interesting": interesting}
    OUT.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print("interesting", len(interesting))


if __name__ == "__main__":
    main()
