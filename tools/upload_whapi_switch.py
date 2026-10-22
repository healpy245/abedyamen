#!/usr/bin/env python3
"""Upload the Whapi transport switch."""

from __future__ import annotations

import ssl
import sys
import urllib.request
from pathlib import Path

import paramiko
from sftp_config import load_sftp

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
HOST, _PORT, USER, PASSWORD = load_sftp()

FILES = [
    "app/Services/AiChatbot/WhapiCloudClient.php",
    "app/Services/AiChatbot/ChatbotGreenApiService.php",
    "app/Services/Malan/Campaigns/MalanCampaignService.php",
    "app/Http/Requests/AiChatbot/UpdateChatbotInstanceRequest.php",
    "app/Http/Requests/AiChatbot/UpdateWorkspaceSettingsRequest.php",
    "app/Http/Controllers/AiChatbot/ChatbotInstanceController.php",
    "app/Http/Controllers/AiChatbot/ChatbotWorkspaceController.php",
    "app/Http/Controllers/AiChatbot/MansourBotController.php",
    "app/Http/Controllers/AiChatbot/MalanCampaignController.php",
    "resources/views/ai-chatbot/partials/whatsapp-provider.blade.php",
    "resources/views/ai-chatbot/instances/edit.blade.php",
    "resources/views/ai-chatbot/workspace/settings.blade.php",
    "resources/views/ai-chatbot/workspace/mansour/settings.blade.php",
    "resources/views/ai-chatbot/workspace/campaigns/settings.blade.php",
    "lang/en/chatbot.php",
    "lang/ar/chatbot.php",
    "lang/he/chatbot.php",
]


def ensure_dir(sftp: paramiko.SFTPClient, remote_dir: str, cache: set[str]) -> None:
    remote_dir = remote_dir.replace("\\", "/")
    if remote_dir in cache or remote_dir in ("", "/"):
        return
    parent = str(Path(remote_dir).parent).replace("\\", "/")
    if parent != remote_dir:
        ensure_dir(sftp, parent, cache)
    try:
        sftp.stat(remote_dir)
    except FileNotFoundError:
        sftp.mkdir(remote_dir)
    cache.add(remote_dir)


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
    sys.exit(main())
