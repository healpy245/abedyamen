#!/usr/bin/env python3
"""SFTP App Development notifications and run migrate/clear."""

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

FILES = [
    "app/Http/Controllers/AppDevelopment/NotificationController.php",
    "app/Http/Controllers/AppDevelopment/TicketCommentController.php",
    "app/Notifications/AppDevelopmentEvent.php",
    "app/Notifications/AppDevelopmentReleaseUploaded.php",
    "app/Providers/AppServiceProvider.php",
    "app/Services/AppDevelopment/AppDevelopmentNotificationService.php",
    "app/Services/AppDevelopment/ReleaseService.php",
    "app/Services/AppDevelopment/TicketAttachmentService.php",
    "app/Services/AppDevelopment/TicketWorkflowService.php",
    "app/View/Composers/NotificationComposer.php",
    "database/migrations/2026_08_24_150000_create_notifications_table.php",
    "lang/ar/app-development.php",
    "lang/en/app-development.php",
    "lang/he/app-development.php",
    "resources/views/app-development/partials/bell.blade.php",
    "resources/views/partials/topbar.blade.php",
    "routes/app-development.php",
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
        print("status", resp.status, flush=True)
        print(resp.read().decode("utf-8", errors="replace")[:2500], flush=True)


def main() -> int:
    transport = paramiko.Transport((HOST, 22))
    transport.connect(username=USER, password=PASSWORD)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    cache: set[str] = set()

    for rel in FILES:
        local = LOCAL / rel
        if not local.is_file():
            print("MISSING", rel, flush=True)
            continue
        remote = f"{REMOTE}/{rel}"
        ensure_dir(sftp, str(Path(remote).parent).replace("\\", "/"), cache)
        sftp.put(str(local), remote)
        print("uploaded", rel, flush=True)

    sftp.close()
    transport.close()
    http_get(f"{SITE}/ops/migrate/483275634")
    http_get(f"{SITE}/ops/clear/483275634")
    return 0


if __name__ == "__main__":
    sys.exit(main())
