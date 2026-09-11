#!/usr/bin/env python3
"""Upload App Development / QA files to production and run migrate/seed."""

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
    "app/Enums/AppDevelopmentRole.php",
    "app/Enums/AppDevelopmentTicketActivityType.php",
    "app/Enums/AppDevelopmentTicketPriority.php",
    "app/Enums/AppDevelopmentTicketStatus.php",
    "app/Enums/AppDevelopmentTicketType.php",
    "app/Enums/Project.php",
    "app/Exceptions/AppDevelopment/TicketWorkflowException.php",
    "app/Http/Controllers/AppDevelopment/DashboardController.php",
    "app/Http/Controllers/AppDevelopment/QaQueueController.php",
    "app/Http/Controllers/AppDevelopment/ReleaseController.php",
    "app/Http/Controllers/AppDevelopment/TicketAttachmentController.php",
    "app/Http/Controllers/AppDevelopment/TicketCommentController.php",
    "app/Http/Controllers/AppDevelopment/TicketController.php",
    "app/Http/Controllers/AppDevelopment/TicketWorkflowController.php",
    "app/Http/Requests/AppDevelopment/AssignTicketRequest.php",
    "app/Http/Requests/AppDevelopment/CompleteTicketRequest.php",
    "app/Http/Requests/AppDevelopment/RejectTicketRequest.php",
    "app/Http/Requests/AppDevelopment/StoreAttachmentRequest.php",
    "app/Http/Requests/AppDevelopment/StoreCommentRequest.php",
    "app/Http/Requests/AppDevelopment/StoreReleaseRequest.php",
    "app/Http/Requests/AppDevelopment/StoreTicketRequest.php",
    "app/Http/Requests/AppDevelopment/SubmitForQaRequest.php",
    "app/Http/Requests/AppDevelopment/UpdateTicketRequest.php",
    "app/Models/AppDevelopment/AppDevelopmentMember.php",
    "app/Models/AppDevelopment/AppDevelopmentRelease.php",
    "app/Models/AppDevelopment/AppDevelopmentReleaseDownload.php",
    "app/Models/AppDevelopment/AppDevelopmentTicket.php",
    "app/Models/AppDevelopment/AppDevelopmentTicketActivity.php",
    "app/Models/AppDevelopment/AppDevelopmentTicketAttachment.php",
    "app/Models/AppDevelopment/AppDevelopmentTicketComment.php",
    "app/Models/User.php",
    "app/Policies/AppDevelopmentReleasePolicy.php",
    "app/Policies/AppDevelopmentTicketPolicy.php",
    "app/Providers/AppServiceProvider.php",
    "app/Services/AppDevelopment/ReleaseService.php",
    "app/Services/AppDevelopment/TicketAttachmentService.php",
    "app/Services/AppDevelopment/TicketNumberService.php",
    "app/Services/AppDevelopment/TicketWorkflowService.php",
    "app/Support/AppDevelopment/FileSize.php",
    "app/View/Composers/AppDevelopmentNavComposer.php",
    "bootstrap/app.php",
    "database/migrations/2026_08_24_120000_create_app_development_tables.php",
    "database/seeders/AppDevelopmentMemberSeeder.php",
    "database/seeders/DatabaseSeeder.php",
    "database/seeders/WorkspaceUserSeeder.php",
    "lang/ar/app-development.php",
    "lang/ar/projects.php",
    "lang/en/app-development.php",
    "lang/en/projects.php",
    "lang/he/app-development.php",
    "lang/he/projects.php",
    "resources/views/app-development/dashboard.blade.php",
    "resources/views/app-development/layouts/project.blade.php",
    "resources/views/app-development/partials/flash.blade.php",
    "resources/views/app-development/partials/nav.blade.php",
    "resources/views/app-development/partials/priority-badge.blade.php",
    "resources/views/app-development/partials/status-badge.blade.php",
    "resources/views/app-development/partials/ticket-row.blade.php",
    "resources/views/app-development/partials/type-badge.blade.php",
    "resources/views/app-development/qa/index.blade.php",
    "resources/views/app-development/releases/create.blade.php",
    "resources/views/app-development/releases/index.blade.php",
    "resources/views/app-development/releases/show.blade.php",
    "resources/views/app-development/tickets/create.blade.php",
    "resources/views/app-development/tickets/edit.blade.php",
    "resources/views/app-development/tickets/index.blade.php",
    "resources/views/app-development/tickets/show.blade.php",
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
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ctx) as resp:
            body = resp.read().decode("utf-8", errors="replace")
            print("status", resp.status, flush=True)
            print(body[:3000], flush=True)
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
    print("SFTP done", len(FILES), "files", flush=True)

    # SSH artisan if the host allows it.
    try:
        client = paramiko.SSHClient()
        client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
        client.connect(HOST, username=USER, password=PASSWORD, timeout=20)
        cmd = (
            "cd /home/tut8x1radmrp/webtimize && "
            "php artisan migrate --force && "
            "php artisan db:seed --class=WorkspaceUserSeeder --force && "
            "php artisan db:seed --class=AppDevelopmentMemberSeeder --force && "
            "php artisan optimize:clear"
        )
        print("SSH", cmd, flush=True)
        _stdin, stdout, stderr = client.exec_command(cmd, timeout=180)
        out = stdout.read().decode("utf-8", errors="replace")
        err = stderr.read().decode("utf-8", errors="replace")
        print("SSH exit", stdout.channel.recv_exit_status(), flush=True)
        print(out[-4000:], flush=True)
        if err.strip():
            print("SSH stderr", err[-2000:], flush=True)
        client.close()
    except Exception as exc:
        print("SSH unavailable:", type(exc).__name__, exc, flush=True)
        print("Falling back to ops HTTP migrate/clear", flush=True)
        http_get(f"{SITE}/ops/migrate/483275634")
        http_get(f"{SITE}/ops/clear/483275634")

    return 0


if __name__ == "__main__":
    sys.exit(main())
