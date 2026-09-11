#!/usr/bin/env python3
from __future__ import annotations

import json
import ssl
import urllib.request
from pathlib import Path

import paramiko

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
SITE = "https://kaman-workspace.com"
SFTP_CFG = json.loads((LOCAL / ".vscode" / "sftp.json").read_text(encoding="utf-8"))

FILES = [
    "app/Http/Controllers/FormController.php",
    "resources/views/form.blade.php",
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
        body = resp.read().decode("utf-8", errors="replace")
        print("status", resp.status, flush=True)
        print(body[:1500], flush=True)


def main() -> int:
    transport = paramiko.Transport((SFTP_CFG["host"], int(SFTP_CFG.get("port", 22))))
    transport.connect(username=SFTP_CFG["username"], password=SFTP_CFG["password"])
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    cache: set[str] = set()
    for rel in FILES:
        local = LOCAL / rel
        remote = f"{REMOTE}/{rel}"
        ensure_dir(sftp, str(Path(remote).parent).replace("\\", "/"), cache)
        sftp.put(str(local), remote)
        print("uploaded", rel, flush=True)
    sftp.close()
    transport.close()
    http_get(f"{SITE}/ops/clear/483275634")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
