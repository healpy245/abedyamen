#!/usr/bin/env python3
"""Upload the Israeli Democrats continuity fix."""

from __future__ import annotations

import ssl
import urllib.request
from pathlib import Path

import paramiko
from sftp_config import load_sftp

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
HOST, _PORT, USER, PASSWORD = load_sftp()

FILES = [
    "app/Services/AiChatbot/DimaPrompt.php",
    "app/Services/AiChatbot/DimaFacts.php",
    "app/Services/AiChatbot/DimaKnowledge.php",
]


def main() -> int:
    transport = paramiko.Transport((HOST, 22))
    transport.connect(username=USER, password=PASSWORD)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    for rel in FILES:
        remote = f"{REMOTE}/{rel}"
        sftp.put(str(LOCAL / rel), remote)
        print(f"uploaded {rel}", flush=True)
    sftp.close()
    transport.close()

    url = "https://kaman-workspace.com/ops/clear/483275634"
    print("GET", url, flush=True)
    req = urllib.request.Request(url, headers={"User-Agent": "deploy-bot"})
    with urllib.request.urlopen(req, timeout=180, context=ssl.create_default_context()) as resp:
        body = resp.read().decode("utf-8", errors="replace")
        print("status", resp.status, flush=True)
        print(body[:1500], flush=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
