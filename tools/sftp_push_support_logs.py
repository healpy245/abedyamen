#!/usr/bin/env python3
"""Upload the logs investigation workspace and clear the view cache."""

from __future__ import annotations

import ssl
import urllib.request
from pathlib import Path

import paramiko
from sftp_config import load_sftp

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
PUBLIC = "/home/tut8x1radmrp/public_html/kaman-workspace"
HOST, _PORT, USER, PASSWORD = load_sftp()

FILES = [
    "app/Services/KamanSupport/LogIssueGrouper.php",
    "app/Http/Controllers/KamanSupport/ConsoleController.php",
    "resources/views/kaman-support/logs.blade.php",
    "public/css/support-logs.css",
    "public/js/support-logs.js",
    "lang/en/kaman-support.php",
    "lang/ar/kaman-support.php",
    "lang/he/kaman-support.php",
]


def ensure_dir(sftp: paramiko.SFTPClient, path: str, cache: set[str]) -> None:
    if path in cache or path in {"", "/"}:
        return
    cur = ""
    for part in path.strip("/").split("/"):
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


def main() -> int:
    transport = paramiko.Transport((HOST, 22))
    transport.connect(username=USER, password=PASSWORD)
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
    url = "https://kaman-workspace.com/ops/clear/483275634"
    print("GET", url, flush=True)
    ctx = ssl.create_default_context()
    req = urllib.request.Request(url, headers={"User-Agent": "deploy-bot"})
    with urllib.request.urlopen(req, timeout=180, context=ctx) as resp:
        print("status", resp.status, flush=True)
        print(resp.read().decode("utf-8", errors="replace")[:1500], flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
