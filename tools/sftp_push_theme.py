#!/usr/bin/env python3
"""SFTP dark/light theme + boot splash, then clear cache."""

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
    "lang/ar/app.php",
    "lang/en/app.php",
    "lang/he/app.php",
    "public/css/app-development.css",
    "public/css/kaman.css",
    "resources/views/app-development/partials/icon.blade.php",
    "resources/views/app-development/partials/modal.blade.php",
    "resources/views/form.blade.php",
    "resources/views/layouts/kaman.blade.php",
    "resources/views/partials/boot-splash.blade.php",
    "resources/views/partials/kaman-ai-loader.blade.php",
    "resources/views/partials/lang-switcher.blade.php",
    "resources/views/partials/theme-boot.blade.php",
    "resources/views/partials/theme-toggle.blade.php",
    "resources/views/partials/topbar.blade.php",
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

    sftp.close()
    transport.close()
    http_get(f"{SITE}/ops/clear/483275634")
    return 0


if __name__ == "__main__":
    sys.exit(main())
