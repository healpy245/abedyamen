#!/usr/bin/env python3
from __future__ import annotations

import json
import re
import ssl
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar
from pathlib import Path

# Reuse the existing console login already present in the sibling audit script.
from _audit_gcp_live import csrf, fetch, opener  # type: ignore

OUT = Path(__file__).resolve().parent / "_visa_292_summary.json"


def login() -> None:
    _, html = fetch("https://kaman-workspace.com/login")
    token = csrf(html)
    src = Path(__file__).resolve().parent.joinpath("_audit_gcp_live.py").read_text(encoding="utf-8")
    email = re.search(r'"email": "([^"]+)"', src).group(1)
    password = re.search(r'"password": "([^"]+)"', src).group(1)
    after, body = fetch(
        "https://kaman-workspace.com/login",
        {"_token": token, "email": email, "password": password, "remember": "1"},
    )
    if "/login" in after:
        raise SystemExit("LOGIN_FAILED")


def get_json(path: str) -> dict:
    _, text = fetch(
        "https://kaman-workspace.com" + path,
        accept="application/json",
    )
    return json.loads(text)


def slim_log(row: dict) -> dict:
    return {
        "at": row.get("at") or row.get("display_at") or row.get("created_at"),
        "level": row.get("level"),
        "source": row.get("source"),
        "device": (row.get("device") or {}).get("name") or row.get("device_name"),
        "message": row.get("message"),
        "context": row.get("context"),
    }


def main() -> None:
    login()
    queries = [
        "pay/visa",
        "pay-visa",
        "319",
        "292",
        "discount",
        "visa",
        "amount",
    ]
    feeds = {}
    for q in queries:
        path = "/kaman-support/live/feed?restaurant=gcp&view=all&q=" + urllib.parse.quote(q)
        try:
            data = get_json(path)
        except Exception as exc:
            feeds[q] = {"error": str(exc)}
            continue
        feeds[q] = {
            "live": [
                {
                    "id": d.get("id"),
                    "name": d.get("name") or d.get("cashier_name") or d.get("hostname"),
                    "role": d.get("role") or d.get("kind"),
                    "failed": d.get("failedCount") or d.get("failed_count"),
                }
                for d in (data.get("live") or [])
            ],
            "logs": [slim_log(row) for row in (data.get("logs") or [])],
        }

    devices = get_json("/kaman-support/live/feed?restaurant=gcp&view=devices")
    device_hits = {}
    for device in (devices.get("live") or []) + (devices.get("recent") or []):
        did = device.get("id")
        if not did:
            continue
        hits = {}
        for q in ("pay/visa", "319", "292", "discount"):
            try:
                data = get_json(
                    f"/kaman-support/live/{did}/feed?view=all&q=" + urllib.parse.quote(q)
                )
            except Exception as exc:
                hits[q] = [{"error": str(exc)}]
                continue
            logs = [slim_log(row) for row in (data.get("logs") or [])]
            if logs:
                hits[q] = logs
        if hits:
            device_hits[str(did)] = {
                "name": device.get("name") or device.get("cashierName") or device.get("hostname"),
                "hits": hits,
            }

    OUT.write_text(
        json.dumps({"feeds": feeds, "devices": device_hits}, ensure_ascii=False, indent=2),
        encoding="utf-8",
    )
    print("WROTE", OUT, "feed_keys", list(feeds), "devices", list(device_hits))


if __name__ == "__main__":
    main()
