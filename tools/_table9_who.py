#!/usr/bin/env python3
import json, urllib.parse
from _find_visa_292 import get_json, login

def main():
    login()
    params = {
        "restaurant": "gcp",
        "view": "all",
        "q": "9ef3509d",
        "from": "2026-09-21 22:15:00",
        "to": "2026-09-21 22:35:00",
    }
    data = get_json("/kaman-support/live/feed?" + urllib.parse.urlencode(params))
    logs = data.get("logs") or []
    print("order logs", len(logs))
    for row in logs:
        msg = row.get("message") or ""
        if "API GET" in msg:
            continue
        dev = row.get("device") or {}
        print(row.get("at"), "dev", dev.get("id"), dev.get("cashier"), dev.get("kind"), msg[:110])

    taps = get_json("/kaman-support/live/feed?" + urllib.parse.urlencode({
        "restaurant": "gcp",
        "view": "all",
        "q": "tapped",
        "from": "2026-09-21 22:17:00",
        "to": "2026-09-21 22:27:00",
    }))
    print("--- taps", len(taps.get("logs") or []))
    for row in taps.get("logs") or []:
        ctx = row.get("context") or {}
        if str(ctx.get("table")) != "9":
            continue
        dev = row.get("device") or {}
        print(row.get("at"), dev.get("id"), dev.get("cashier"), row.get("message"), "cart", ctx.get("cart"), "screen", ctx.get("screen"))

if __name__ == "__main__":
    main()
