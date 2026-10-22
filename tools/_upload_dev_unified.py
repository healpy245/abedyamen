#!/usr/bin/env python3
"""Upload the unified Development door files and clear the production cache."""

from __future__ import annotations

import ssl
import urllib.request
from pathlib import Path

import paramiko
from sftp_config import load_sftp

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
PUBLIC = "/home/tut8x1radmrp/public_html/kaman-workspace"

FILES = [
    "app/Http/Controllers/AppDevelopment/ReportController.php",
    "routes/app-development.php",
    "lang/en/app-development.php",
    "resources/views/app-development/partials/nav.blade.php",
    "resources/views/app-development/reports/index.blade.php",
    "public/css/app-development-door.css",
]


def ensure_dir(sftp: paramiko.SFTPClient, path: str, cache: set[str]) -> None:
    if path in cache or path in {"", "/"}:
        return
    parent, _, _name = path.rpartition("/")
    if parent:
        ensure_dir(sftp, parent, cache)
    try:
        sftp.stat(path)
    except FileNotFoundError:
        sftp.mkdir(path)
    cache.add(path)


def put(sftp: paramiko.SFTPClient, local_rel: str, remote: str, cache: set[str]) -> None:
    parent, _, _name = remote.rpartition("/")
    ensure_dir(sftp, parent, cache)
    sftp.put(str(LOCAL / local_rel), remote)
    print("uploaded", remote, flush=True)


def main() -> None:
    host, port, user, password = load_sftp()
    transport = paramiko.Transport((host, port))
    transport.connect(username=user, password=password)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    cache: set[str] = set()
    for rel in FILES:
        put(sftp, rel, f"{REMOTE}/{rel}", cache)
        if rel.startswith("public/"):
            put(sftp, rel, f"{PUBLIC}/{rel.removeprefix('public/')}", cache)
    sftp.close()
    transport.close()

    url = "https://kaman-workspace.com/ops/clear/483275634"
    request = urllib.request.Request(url, headers={"User-Agent": "deploy-bot"})
    with urllib.request.urlopen(request, timeout=120, context=ssl.create_default_context()) as response:
        print("clear", response.status, flush=True)
        print(response.read().decode("utf-8", errors="replace")[:300], flush=True)


if __name__ == "__main__":
    main()
