#!/usr/bin/env python3
"""Trace the latest GCP table-9 order. Temporary."""
from __future__ import annotations

import json
import re
import urllib.parse
from datetime import datetime, timedelta
from pathlib import Path

from _find_visa_292 import get_json, login

OUT = Path(__file__).resolve().parent / "_table9_trace.json"
NOW = datetime(2026, 9, 21, 22, 40)
START = NOW - timedelta(hours=3)


def windows(step_min: int):
    t = START
    while t < NOW:
        nxt = min(t + timedelta(minutes=step_min), NOW)
        yield t.strftime("%Y-%m-%d %H:%M:%S"), nxt.strftime("%Y-%m-%d %H:%M:%S")
        t = nxt


def pull(params: dict) -> list[dict]:
    path = "/kaman-support/live/feed?" + urllib.parse.urlencode({"restaurant": "gcp", "view": "all", **params})
    data = get_json(path)
    return data.get("logs") or []


def main() -> None:
    login()
    taps = []
    seen = set()
    for a, b in windows(20):
        rows = pull({"q": "tapped", "from": a, "to": b})
        for row in rows:
            msg = row.get("message") or ""
            if not re.search(r"tapped\s+9(\D|$)", msg) and "طاولة 9" not in msg and "طاولة9" not in msg:
                # also keep nearby table taps that are exactly a small number
                if not re.search(r"tapped\s+(طاولة\s*)?9\b", msg):
                    continue
            if row.get("id") in seen:
                continue
            seen.add(row.get("id"))
            taps.append({"at": row.get("at"), "message": msg, "context": row.get("context"), "device": (row.get("device") or {}).get("cashier")})
        print("taps", a, len(rows), "kept", len(taps))

    mutations = []
    seen_m = set()
    for a, b in windows(10):
        rows = pull({"q": "/api/cashier/orders", "from": a, "to": b})
        added = 0
        for row in rows:
            msg = row.get("message") or ""
            if "API GET " in msg:
                continue
            if row.get("id") in seen_m:
                continue
            seen_m.add(row.get("id"))
            mutations.append({
                "at": row.get("at"),
                "cashier": (row.get("device") or {}).get("cashier"),
                "message": msg,
            })
            added += 1
        print("mut", a, "returned", len(rows), "new", added)

    # prints that mention table 9
    prints = []
    for a, b in windows(30):
        rows = pull({"q": "9", "from": a, "to": b})
        for row in rows:
            msg = row.get("message") or ""
            src = row.get("source") or ""
            if src == "api":
                continue
            if not re.search(r"(طاولة\s*9\b|\btable\s*9\b|Table\s*9)", msg, re.I):
                continue
            prints.append({"at": row.get("at"), "source": src, "message": msg[:400], "cashier": (row.get("device") or {}).get("cashier")})

    payload = {"taps": taps, "mutation_count": len(mutations), "mutations": mutations, "prints": prints}
    OUT.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    print("taps", len(taps), "mutations", len(mutations), "prints", len(prints))


if __name__ == "__main__":
    main()
