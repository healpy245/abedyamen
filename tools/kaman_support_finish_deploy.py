#!/usr/bin/env python3
"""Finish production deploy: set API key via SFTP, then HTTP migrate/clear."""

from __future__ import annotations

import ssl
import urllib.request
from pathlib import Path

import paramiko

LOCAL = Path(__file__).resolve().parents[1]
REMOTE_ENV = "/home/tut8x1radmrp/webtimize/.env"
HOST = "sg2plzcpnl505722.prod.sin2.secureserver.net"
USER = "tut8x1radmrp"
PASSWORD = "Mfit2025@"
SITE = "https://kaman-workspace.com"


def load_key() -> str:
    for line in (LOCAL / ".kaman-support.env").read_text(encoding="utf-8").splitlines():
        if line.startswith("KAMAN_SUPPORT_API_KEY="):
            value = line.split("=", 1)[1].strip().strip('"').strip("'")
            if value:
                return value
    raise SystemExit("missing local KAMAN_SUPPORT_API_KEY")


def http_get(url: str, timeout: int = 180) -> str:
    print("GET", url, flush=True)
    ctx = ssl.create_default_context()
    req = urllib.request.Request(url, headers={"User-Agent": "deploy-bot"})
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            print("status", resp.status, flush=True)
            print(body[:3000], flush=True)
            return body
    except Exception as exc:
        print("HTTP FAIL", type(exc).__name__, exc, flush=True)
        if hasattr(exc, "read"):
            try:
                print(exc.read().decode("utf-8", errors="replace")[:2000], flush=True)
            except Exception:
                pass
        return ""


def main() -> int:
    key = load_key()
    transport = paramiko.Transport((HOST, 22))
    transport.connect(username=USER, password=PASSWORD)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None

    with sftp.file(REMOTE_ENV, "r") as fh:
        text = fh.read().decode("utf-8", errors="replace")

    found = False
    changed = False
    out: list[str] = []
    for line in text.splitlines():
        if line.startswith("KAMAN_SUPPORT_API_KEY="):
            current = line.split("=", 1)[1].strip().strip('"').strip("'")
            found = True
            if current:
                out.append(line)
            else:
                out.append("KAMAN_SUPPORT_API_KEY=" + key)
                changed = True
        else:
            out.append(line)

    if not found:
        if out and out[-1] != "":
            out.append("")
        out.append("KAMAN_SUPPORT_API_KEY=" + key)
        out.append("")
        changed = True

    if changed:
        with sftp.file(REMOTE_ENV, "w") as fh:
            fh.write("\n".join(out) + "\n")
        print("remote .env updated: KAMAN_SUPPORT_API_KEY set", flush=True)
    else:
        print("remote .env already has KAMAN_SUPPORT_API_KEY", flush=True)

    sftp.close()
    transport.close()

    http_get(f"{SITE}/ops/migrate/483275634")
    http_get(f"{SITE}/ops/clear/483275634")
    http_get(f"{SITE}/api/kaman-support/v1/health")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
