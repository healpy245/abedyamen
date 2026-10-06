#!/usr/bin/env python3
"""Upload one relative path and optionally print a URL body."""

from __future__ import annotations

import json
import ssl
import sys
import urllib.request
from pathlib import Path

import paramiko

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"


def main() -> int:
    rel = sys.argv[1]
    cfg = json.loads((LOCAL / ".vscode" / "sftp.json").read_text(encoding="utf-8"))
    transport = paramiko.Transport((cfg["host"], 22))
    transport.connect(username=cfg["username"], password=cfg["password"])
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    local = LOCAL / rel
    remote = f"{REMOTE}/{rel.replace(chr(92), '/')}"
    sftp.put(str(local), remote)
    print(f"uploaded {rel}")
    sftp.close()
    transport.close()
    if len(sys.argv) > 2:
        url = sys.argv[2]
        req = urllib.request.Request(url, headers={"User-Agent": "deploy-bot"})
        with urllib.request.urlopen(req, context=ssl.create_default_context(), timeout=120) as resp:
            print(resp.read().decode("utf-8", "replace")[:20000])
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
