#!/usr/bin/env python3
"""SFTP the App Development modal changes to production."""

from __future__ import annotations

import ssl
import sys
import urllib.request
from pathlib import Path

import paramiko

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
PUBLIC = "/home/tut8x1radmrp/public_html/kaman-workspace"
HOST = "sg2plzcpnl505722.prod.sin2.secureserver.net"
USER = "tut8x1radmrp"
PASSWORD = "Mfit2025@"
SITE = "https://kaman-workspace.com"

FILES = [
    "app/Http/Controllers/AppDevelopment/RendersAppDevelopmentModal.php",
    "app/Http/Controllers/AppDevelopment/TicketController.php",
    "app/Http/Controllers/AppDevelopment/ReleaseController.php",
    "lang/ar/app-development.php",
    "lang/en/app-development.php",
    "lang/he/app-development.php",
    "public/css/app-development.css",
    "public/js/app-development-modal.js",
    "resources/views/app-development/layouts/modal-host.blade.php",
    "resources/views/app-development/layouts/project.blade.php",
    "resources/views/app-development/partials/modal.blade.php",
    "resources/views/app-development/partials/nav.blade.php",
    "resources/views/app-development/partials/ticket-row.blade.php",
    "resources/views/app-development/qa/index.blade.php",
    "resources/views/app-development/releases/form-create.blade.php",
    "resources/views/app-development/releases/index.blade.php",
    "resources/views/app-development/releases/panel.blade.php",
    "resources/views/app-development/tickets/form-create.blade.php",
    "resources/views/app-development/tickets/form-edit.blade.php",
    "resources/views/app-development/tickets/index.blade.php",
    "resources/views/app-development/tickets/panel.blade.php",
]

DELETE = [
    "resources/views/app-development/tickets/create.blade.php",
    "resources/views/app-development/tickets/edit.blade.php",
    "resources/views/app-development/tickets/show.blade.php",
    "resources/views/app-development/releases/create.blade.php",
    "resources/views/app-development/releases/show.blade.php",
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
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            print("status", resp.status, flush=True)
            print(body[:2000], flush=True)
    except Exception as exc:
        print("HTTP FAIL", type(exc).__name__, exc, flush=True)


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
            continue
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

    for rel in DELETE:
        remote = f"{REMOTE}/{rel}"
        try:
            sftp.remove(remote)
            print("deleted", rel, flush=True)
        except FileNotFoundError:
            print("already gone", rel, flush=True)

    sftp.close()
    transport.close()
    print("SFTP done", flush=True)
    http_get(f"{SITE}/ops/clear/483275634")
    return 0


if __name__ == "__main__":
    sys.exit(main())
