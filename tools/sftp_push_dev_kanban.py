#!/usr/bin/env python3
"""Upload the development Kanban redesign, then clear the view cache."""

from __future__ import annotations

import ssl
import sys
import urllib.request
from pathlib import Path

import paramiko
from sftp_config import load_sftp

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
PUBLIC = "/home/tut8x1radmrp/public_html/kaman-workspace"

HOST, _SFTP_PORT, USER, PASSWORD = load_sftp()
SITE = "https://kaman-workspace.com"

FILES = [
    "lang/ar/app-development.php",
    "lang/en/app-development.php",
    "lang/he/app-development.php",
    "public/css/app-development-board.css",
    "public/js/app-development-board.js",
    "resources/views/app-development/tasks/index.blade.php",
    "resources/views/app-development/tasks/panel.blade.php",
    "resources/views/app-development/tasks/partials/card.blade.php",
]

REMOVE = [
    "app/Support/AppDevelopment/BoardStages.php",
    "resources/views/app-development/tickets/partials/card.blade.php",
    "resources/views/app-development/tickets/partials/list.blade.php",
]


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


def http_get(url: str, timeout: int = 180) -> None:
    print("GET", url, flush=True)
    ctx = ssl.create_default_context()
    req = urllib.request.Request(url, headers={"User-Agent": "deploy-bot"})
    with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp:
        print("status", resp.status, flush=True)
        print(resp.read().decode("utf-8", errors="replace")[:2000], flush=True)


def main() -> int:
    transport = paramiko.Transport((HOST, 22))
    transport.connect(username=USER, password=PASSWORD)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    cache: set[str] = set()

    for rel in FILES:
        local = LOCAL / rel
        if not local.is_file():
            print("MISSING", rel, flush=True)
            return 1
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

    for rel in REMOVE:
        remote = f"{REMOTE}/{rel}"
        try:
            sftp.remove(remote)
            print("removed", rel, flush=True)
        except FileNotFoundError:
            print("already gone", rel, flush=True)

    sftp.close()
    transport.close()
    http_get(f"{SITE}/ops/clear/483275634")
    return 0


if __name__ == "__main__":
    sys.exit(main())
