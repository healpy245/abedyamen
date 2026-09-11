#!/usr/bin/env python3
"""SFTP page-scoped New Ticket / New APK buttons, then clear cache."""

from __future__ import annotations

import json
import ssl
import sys
import urllib.request
from pathlib import Path

import paramiko

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
SITE = "https://kaman-workspace.com"

FILES = [
    "resources/views/app-development/partials/nav.blade.php",
    "resources/views/app-development/layouts/project.blade.php",
    "resources/views/app-development/tickets/index.blade.php",
    "resources/views/app-development/releases/index.blade.php",
]


def load_sftp_config() -> tuple[str, str, str]:
    cfg = json.loads((LOCAL / ".vscode" / "sftp.json").read_text(encoding="utf-8"))
    return cfg["host"], cfg["username"], cfg["password"]


def ensure_remote_dir(sftp: paramiko.SFTPClient, remote_dir: str) -> None:
    parts = remote_dir.strip("/").split("/")
    path = ""
    for part in parts:
        path += "/" + part
        try:
            sftp.stat(path)
        except FileNotFoundError:
            sftp.mkdir(path)


def upload(sftp: paramiko.SFTPClient, rel: str) -> None:
    local = LOCAL / rel
    if not local.is_file():
        raise FileNotFoundError(rel)
    remote = f"{REMOTE}/{rel.replace(chr(92), '/')}"
    ensure_remote_dir(sftp, str(Path(remote).parent).replace("\\", "/"))
    sftp.put(str(local), remote)
    print(f"uploaded {rel}")


def hit(path: str) -> None:
    url = f"{SITE}{path}"
    ctx = ssl.create_default_context()
    req = urllib.request.Request(url, headers={"User-Agent": "deploy-bot"})
    with urllib.request.urlopen(req, context=ctx, timeout=120) as resp:
        body = resp.read(200).decode("utf-8", errors="replace")
        print(f"GET {path} -> {resp.status} {body[:120]!r}")


def main() -> int:
    host, user, password = load_sftp_config()
    transport = paramiko.Transport((host, 22))
    transport.connect(username=user, password=password)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    try:
        for rel in FILES:
            upload(sftp, rel)
    finally:
        sftp.close()
        transport.close()

    hit("/ops/clear/483275634")
    return 0


if __name__ == "__main__":
    sys.exit(main())
