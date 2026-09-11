#!/usr/bin/env python3
"""Upload the seed ops route and run migrate/seed/clear over HTTP."""

from __future__ import annotations

import ssl
import sys
import urllib.request
from pathlib import Path

import paramiko

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
HOST = "sg2plzcpnl505722.prod.sin2.secureserver.net"
USER = "tut8x1radmrp"
PASSWORD = "Mfit2025@"
SITE = "https://kaman-workspace.com"


def http_get(url: str, timeout: int = 180) -> None:
    print("GET", url, flush=True)
    ctx = ssl.create_default_context()
    req = urllib.request.Request(url, headers={"User-Agent": "deploy-bot"})
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            print("status", resp.status, flush=True)
            print(body[:4000], flush=True)
    except Exception as exc:
        print("HTTP FAIL", type(exc).__name__, exc, flush=True)
        if hasattr(exc, "read"):
            try:
                print(exc.read().decode("utf-8", errors="replace")[:2000], flush=True)
            except Exception:
                pass


def main() -> int:
    transport = paramiko.Transport((HOST, 22))
    transport.connect(username=USER, password=PASSWORD)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    local = LOCAL / "routes" / "web.php"
    remote = f"{REMOTE}/routes/web.php"
    sftp.put(str(local), remote)
    print("uploaded routes/web.php", flush=True)
    sftp.close()
    transport.close()

    http_get(f"{SITE}/ops/migrate/483275634")
    http_get(f"{SITE}/ops/seed/483275636")
    http_get(f"{SITE}/ops/clear/483275634")
    return 0


if __name__ == "__main__":
    sys.exit(main())
