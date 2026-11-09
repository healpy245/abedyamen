#!/usr/bin/env python3
"""Deploy the one-bot-per-number change and the fast line check.

  python tools/upload_one_bot_numbers.py fetch DIR   # download live copies to compare
  python tools/upload_one_bot_numbers.py push        # upload, migrate, clear caches
"""

from __future__ import annotations

import ssl
import sys
import urllib.request
from pathlib import Path

import paramiko
from sftp_config import load_sftp

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
PUBLIC = "/home/tut8x1radmrp/public_html/kaman-workspace"
HOST, PORT, USER, PASSWORD = load_sftp()

FILES = [
    "app/Services/AiChatbot/MansourLines.php",
    "app/Services/AiChatbot/MowahadaLinePool.php",
    "app/Services/AiChatbot/MansourBotService.php",
    "app/Http/Controllers/AiChatbot/MansourBotController.php",
    "app/Http/Controllers/AiChatbot/MowahadaLinesController.php",
    "app/Http/Middleware/KeepMowahadaInFarz.php",
    "routes/ai-chatbot.php",
    "resources/views/ai-chatbot/workspace/mansour/settings.blade.php",
    "resources/views/ai-chatbot/workspace/mansour/pool-lines.blade.php",
    "resources/views/ai-chatbot/farz/numbers.blade.php",
    "public/css/farz-theme.css",
    "database/migrations/2026_10_22_120000_keep_each_mansour_line_on_one_bot.php",
]


def connect() -> tuple[paramiko.Transport, paramiko.SFTPClient]:
    transport = paramiko.Transport((HOST, PORT))
    transport.connect(username=USER, password=PASSWORD)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    return transport, sftp


def fetch(target: Path) -> None:
    transport, sftp = connect()
    for rel in FILES:
        out = target / rel
        out.parent.mkdir(parents=True, exist_ok=True)
        try:
            sftp.get(f"{REMOTE}/{rel}", str(out))
            print(f"fetched {rel}", flush=True)
        except FileNotFoundError:
            print(f"missing {rel}", flush=True)
    sftp.close()
    transport.close()


def get(url: str) -> None:
    print("GET", url, flush=True)
    req = urllib.request.Request(url, headers={"User-Agent": "deploy-bot"})
    try:
        with urllib.request.urlopen(req, timeout=180, context=ssl.create_default_context()) as resp:
            print("status", resp.status, flush=True)
            print(resp.read().decode("utf-8", errors="replace")[:1500], flush=True)
    except urllib.error.HTTPError as e:
        print("status", e.code, flush=True)
        print(e.read().decode("utf-8", errors="replace")[:1500], flush=True)
        raise


def push() -> None:
    transport, sftp = connect()
    for rel in FILES:
        sftp.put(str(LOCAL / rel), f"{REMOTE}/{rel}")
        print(f"uploaded {rel}", flush=True)
        if rel.startswith("public/"):
            sftp.put(str(LOCAL / rel), f"{PUBLIC}/{rel.removeprefix('public/')}")
            print(f"uploaded public_html {rel.removeprefix('public/')}", flush=True)
    sftp.close()
    transport.close()
    get("https://kaman-workspace.com/ops/migrate/483275634")
    get("https://kaman-workspace.com/ops/clear/483275634")


if __name__ == "__main__":
    if len(sys.argv) > 2 and sys.argv[1] == "fetch":
        fetch(Path(sys.argv[2]))
    elif len(sys.argv) > 1 and sys.argv[1] == "push":
        push()
    else:
        raise SystemExit(__doc__)
