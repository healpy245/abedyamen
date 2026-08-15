#!/usr/bin/env python3
"""Upload selected project files to webtimize via SFTP."""

from __future__ import annotations

import sys
from pathlib import Path

import paramiko

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
HOST = "sg2plzcpnl505722.prod.sin2.secureserver.net"
USER = "tut8x1radmrp"
PASSWORD = "Mfit2025@"

FILES = [
    "app/Models/AiChatbot/ChatbotInstance.php",
    "app/Services/AiChatbot/ChatbotGreenApiService.php",
    "app/Http/Controllers/AiChatbot/ChatbotInstanceController.php",
    "app/Http/Requests/AiChatbot/UpdateChatbotInstanceRequest.php",
    "resources/views/ai-chatbot/instances/edit.blade.php",
    "database/seeders/SallyMalanChatbotInstanceSeeder.php",
    "routes/web.php",
    "lang/en/chatbot.php",
    "lang/ar/chatbot.php",
    "lang/he/chatbot.php",
]


def main() -> int:
    transport = paramiko.Transport((HOST, 22))
    transport.connect(username=USER, password=PASSWORD)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None

    for rel in FILES:
        local = LOCAL / rel
        remote = f"{REMOTE}/{rel}"
        sftp.put(str(local), remote)
        print(f"uploaded {rel}", flush=True)

    sftp.close()
    transport.close()
    return 0


if __name__ == "__main__":
    sys.exit(main())
