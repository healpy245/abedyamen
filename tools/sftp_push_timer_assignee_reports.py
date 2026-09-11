#!/usr/bin/env python3
"""SFTP assignee-only timers + organized reports."""

from __future__ import annotations

import json
import ssl
import sys
import urllib.request
from pathlib import Path

import paramiko

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
PUBLIC = "/home/tut8x1radmrp/public_html/kaman-workspace"
SITE = "https://kaman-workspace.com"

FILES = [
    "app/Policies/AppDevelopmentTaskPolicy.php",
    "resources/views/app-development/reports/index.blade.php",
    "public/css/app-development.css",
]


def load_sftp_config() -> tuple[str, str, str]:
    cfg = json.loads((LOCAL / ".vscode" / "sftp.json").read_text(encoding="utf-8"))
    return cfg["host"], cfg["username"], cfg["password"]


def ensure_dir(sftp: paramiko.SFTPClient, path: str, cache: set[str]) -> None:
    if path in cache or path in {"", "/"}:
        return
    parts = path.strip("/").split("/")
    cur = ""
    for part in parts:
        cur += "/" + part
        if cur in cache:
            continue
        try:
            sftp.stat(cur)
        except FileNotFoundError:
            try:
                sftp.mkdir(cur)
            except OSError:
                pass
        cache.add(cur)


def http_get(url: str) -> None:
    print("GET", url, flush=True)
    ctx = ssl.create_default_context()
    req = urllib.request.Request(url, headers={"User-Agent": "deploy-bot"})
    with urllib.request.urlopen(req, timeout=120, context=ctx) as resp:
        print("status", resp.status, flush=True)
        print(resp.read().decode("utf-8", errors="replace")[:300], flush=True)


def main() -> int:
    host, user, password = load_sftp_config()
    transport = paramiko.Transport((host, 22))
    transport.connect(username=user, password=password)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    cache: set[str] = set()
    for rel in FILES:
        local = LOCAL / rel
        remote = f"{REMOTE}/{rel}"
        ensure_dir(sftp, str(Path(remote).parent).replace("\\", "/"), cache)
        sftp.put(str(local), remote)
        print("uploaded", rel, flush=True)
        if rel.startswith("public/"):
            public_rel = rel.removeprefix("public/")
            public_remote = f"{PUBLIC}/{public_rel}"
            ensure_dir(sftp, str(Path(public_remote).parent).replace("\\", "/"), cache)
            sftp.put(str(local), public_remote)
            print("uploaded public_html", public_rel, flush=True)
    sftp.close()
    transport.close()
    http_get(f"{SITE}/ops/clear/483275634")
    return 0


if __name__ == "__main__":
    sys.exit(main())
