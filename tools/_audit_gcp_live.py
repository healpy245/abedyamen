#!/usr/bin/env python3
from __future__ import annotations

import json
import re
import ssl
import urllib.parse
import urllib.request
from http.cookiejar import CookieJar
from pathlib import Path

OUT = Path(__file__).resolve().parent / "_gcp_live_audit.json"

ctx = ssl.create_default_context()
cj = CookieJar()
opener = urllib.request.build_opener(
    urllib.request.HTTPCookieProcessor(cj),
    urllib.request.HTTPSHandler(context=ctx),
)
opener.addheaders = [("User-Agent", "kaman-support-audit")]


def fetch(url: str, data: dict | None = None, accept: str = "text/html") -> tuple[str, str]:
    body = None
    req = urllib.request.Request(url)
    req.add_header("Accept", accept)
    if data is not None:
        body = urllib.parse.urlencode(data).encode()
        req.add_header("Content-Type", "application/x-www-form-urlencoded")
        req.add_header("X-Requested-With", "XMLHttpRequest")
    with opener.open(req, data=body, timeout=45) as resp:
        return resp.geturl(), resp.read().decode("utf-8", "replace")


def csrf(html: str) -> str:
    for pat in (
        r'name="_token" value="([^"]+)"',
        r'csrf-token" content="([^"]+)"',
    ):
        m = re.search(pat, html)
        if m:
            return m.group(1)
    return ""


def main() -> None:
    url, html = fetch("https://kaman-workspace.com/login")
    token = csrf(html)
    after, body = fetch(
        "https://kaman-workspace.com/login",
        {
            "_token": token,
            "email": "yamen@kaman.rest",
            "password": "yam1234@",
            "remember": "1",
        },
    )
    result: dict = {
        "login_url": url,
        "after_login": after,
        "logged_in": "/login" not in after,
        "csrf": bool(token),
    }
    if "/login" in after:
        result["login_error"] = body[:800]
        OUT.write_text(json.dumps(result, ensure_ascii=False, indent=2), encoding="utf-8")
        print("LOGIN_FAILED", after)
        return

    pages = {}
    for name, path in {
        "live_gcp": "/kaman-support/live?restaurant=gcp",
        "live_all": "/kaman-support/live",
        "feed_gcp": "/kaman-support/live/feed?restaurant=gcp&view=all",
        "feed_bugs": "/kaman-support/live/feed?restaurant=gcp&view=bugs",
        "feed_api": "/kaman-support/live/feed?restaurant=gcp&view=api",
        "feed_devices": "/kaman-support/live/feed?restaurant=gcp&view=devices",
    }.items():
        final, text = fetch(
            "https://kaman-workspace.com" + path,
            accept="application/json,text/html",
        )
        pages[name] = {"url": final, "text": text}

    devices = []
    try:
        feed = json.loads(pages["feed_gcp"]["text"])
        devices = (feed.get("live") or []) + (feed.get("recent") or [])
    except json.JSONDecodeError:
        feed = {"raw": pages["feed_gcp"]["text"][:2000]}

    device_pages = {}
    for device in devices:
        if (device.get("restaurant") or "").lower() not in {"gcp", "gcp.kaman.rest"} and "gcp" not in str(device.get("restaurantName") or "").lower():
            continue
        did = device.get("id")
        if not did:
            continue
        final, text = fetch(
            f"https://kaman-workspace.com/kaman-support/live/{did}/feed?view=all",
            accept="application/json",
        )
        try:
            payload = json.loads(text)
        except json.JSONDecodeError:
            payload = {"raw": text[:2000]}
        device_pages[str(did)] = payload

    OUT.write_text(
        json.dumps(
            {
                "meta": result,
                "feed_gcp": json.loads(pages["feed_gcp"]["text"]) if pages["feed_gcp"]["text"].lstrip().startswith("{") else pages["feed_gcp"]["text"][:3000],
                "feed_bugs": json.loads(pages["feed_bugs"]["text"]) if pages["feed_bugs"]["text"].lstrip().startswith("{") else pages["feed_bugs"]["text"][:1500],
                "feed_api": json.loads(pages["feed_api"]["text"]) if pages["feed_api"]["text"].lstrip().startswith("{") else pages["feed_api"]["text"][:1500],
                "devices": device_pages,
            },
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )
    print("WROTE", OUT, "devices", list(device_pages))


if __name__ == "__main__":
    main()
