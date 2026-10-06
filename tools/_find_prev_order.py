#!/usr/bin/env python3
from __future__ import annotations

import json
import urllib.parse
from pathlib import Path

from _find_visa_292 import get_json, login, slim_log

OUT = Path(__file__).resolve().parent / "_prev_order_trail.json"

IDS = [
    "01a0c38e",
    "01a0c385",
    "01a0c38c",
    "01a0c38a",
    "01a0c39b",
]


def main() -> None:
    login()
    feeds: dict = {}
    for q in IDS:
        path = "/kaman-support/live/feed?restaurant=gcp&view=all&q=" + urllib.parse.quote(q)
        try:
            data = get_json(path)
            feeds[q] = [slim_log(row) for row in (data.get("logs") or [])]
        except Exception as exc:
            feeds[q] = [{"error": str(exc)}]

    # Extra searches for the create/update bodies of the previous ticket.
    for q in ("01a0c38e-5eed", "body={\"order_method\"", "9ef475eb", "9ef47555"):
        path = "/kaman-support/live/feed?restaurant=gcp&view=all&q=" + urllib.parse.quote(q)
        try:
            data = get_json(path)
            feeds[q] = [slim_log(row) for row in (data.get("logs") or [])]
        except Exception as exc:
            feeds[q] = [{"error": str(exc)}]

    OUT.write_text(json.dumps(feeds, ensure_ascii=False, indent=2), encoding="utf-8")
    print("WROTE", OUT)


if __name__ == "__main__":
    main()
