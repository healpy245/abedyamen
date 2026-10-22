#!/usr/bin/env python3
"""Upload the invitation bot, its poster, and the split-reply wait."""

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
    "app/Http/Controllers/AiChatbot/ChatbotController.php",
    "app/Http/Controllers/AiChatbot/ChatbotWorkspaceController.php",
    "app/Http/Controllers/AiChatbot/FarzDashboardController.php",
    "app/Jobs/ProcessMansourConversationJob.php",
    "app/Models/AiChatbot/ChatbotInstance.php",
    "app/Services/AiChatbot/AiChatbotService.php",
    "app/Services/AiChatbot/ChatbotGreenApiService.php",
    "app/Services/AiChatbot/ChatbotTestService.php",
    "app/Services/AiChatbot/FarzDashboardService.php",
    "app/Services/AiChatbot/InviteInsightService.php",
    "app/Services/AiChatbot/InvitePrompt.php",
    "app/Services/AiChatbot/MansourBotService.php",
    "app/Services/AiChatbot/MansourConversationRevival.php",
    "app/Services/AiChatbot/MansourLines.php",
    "app/Support/FarzUi.php",
    "resources/views/ai-chatbot/farz/dashboard.blade.php",
    "resources/views/ai-chatbot/workspace/mansour/analytics.blade.php",
    "resources/views/ai-chatbot/workspace/test.blade.php",
    "public/images/farz/womens-conference.jpg",
    "public/js/workspace-test-chat.js",
]


def ensure_dir(sftp: paramiko.SFTPClient, path: str, cache: set[str]) -> None:
    if path in cache or path in {"", "/"}:
        return
    parent = path.rsplit("/", 1)[0]
    ensure_dir(sftp, parent, cache)
    try:
        sftp.stat(path)
    except FileNotFoundError:
        sftp.mkdir(path)
    cache.add(path)


def main() -> int:
    transport = paramiko.Transport((HOST, 22))
    transport.connect(username=USER, password=PASSWORD)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    cache: set[str] = set()
    missing = []
    for rel in FILES:
        local = LOCAL / rel
        if not local.is_file():
            missing.append(rel)
            print(f"missing {rel}", flush=True)
            continue
        remote = f"{REMOTE}/{rel}"
        ensure_dir(sftp, remote.rsplit("/", 1)[0], cache)
        sftp.put(str(local), remote)
        print(f"uploaded {rel}", flush=True)
        if rel.startswith("public/"):
            public_rel = rel[len("public/") :]
            public_remote = f"{PUBLIC}/{public_rel}"
            ensure_dir(sftp, public_remote.rsplit("/", 1)[0], cache)
            sftp.put(str(local), public_remote)
            print(f"public {public_rel}", flush=True)
    sftp.close()
    transport.close()
    if missing:
        raise SystemExit("missing files: " + ", ".join(missing))

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
