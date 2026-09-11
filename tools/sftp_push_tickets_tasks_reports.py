#!/usr/bin/env python3
"""SFTP App Types + Tasks + Sidebar + Reports, then migrate + clear cache."""

from __future__ import annotations

import json
import ssl
import sys
import urllib.request
from pathlib import Path

import paramiko

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
PUBLIC = "/home/tut8x1radmrp/public_html/kaman-workspace"
SITE = "https://kaman-workspace.com"

FILES = [
    "app/Enums/AppDevelopmentAppType.php",
    "app/Enums/AppDevelopmentTaskStatus.php",
    "app/Exceptions/AppDevelopment/TaskTimerException.php",
    "app/Http/Controllers/AppDevelopment/TicketController.php",
    "app/Http/Controllers/AppDevelopment/TicketWorkflowController.php",
    "app/Http/Controllers/AppDevelopment/TaskController.php",
    "app/Http/Controllers/AppDevelopment/TaskTimerController.php",
    "app/Http/Controllers/AppDevelopment/ReportController.php",
    "app/Http/Requests/AppDevelopment/StoreTicketRequest.php",
    "app/Http/Requests/AppDevelopment/UpdateTicketRequest.php",
    "app/Http/Requests/AppDevelopment/UpdateTicketAppTypesRequest.php",
    "app/Http/Requests/AppDevelopment/StoreTaskRequest.php",
    "app/Http/Requests/AppDevelopment/UpdateTaskRequest.php",
    "app/Http/Requests/AppDevelopment/CompleteTaskRequest.php",
    "app/Http/Requests/AppDevelopment/UpdateTimeEntryRequest.php",
    "app/Models/AppDevelopment/AppDevelopmentTicket.php",
    "app/Models/AppDevelopment/AppDevelopmentTicketAppType.php",
    "app/Models/AppDevelopment/AppDevelopmentTask.php",
    "app/Models/AppDevelopment/AppDevelopmentTimeEntry.php",
    "app/Notifications/AppDevelopmentTaskAssigned.php",
    "app/Policies/AppDevelopmentTicketPolicy.php",
    "app/Policies/AppDevelopmentTaskPolicy.php",
    "app/Policies/AppDevelopmentTimeEntryPolicy.php",
    "app/Providers/AppServiceProvider.php",
    "app/Services/AppDevelopment/AppDevelopmentNotificationService.php",
    "app/Services/AppDevelopment/TaskTimerService.php",
    "app/View/Composers/AppDevelopmentNavComposer.php",
    "app/View/Composers/AppDevelopmentTimerComposer.php",
    "database/migrations/2026_08_27_100000_create_app_development_ticket_app_types_table.php",
    "database/migrations/2026_08_27_110000_create_app_development_tasks_table.php",
    "database/migrations/2026_08_27_120000_create_app_development_time_entries_table.php",
    "lang/en/app-development.php",
    "lang/ar/app-development.php",
    "lang/he/app-development.php",
    "public/css/app-development.css",
    "public/js/app-development-inline.js",
    "public/js/app-development-sidebar.js",
    "public/js/app-development-timer.js",
    "resources/views/app-development/layouts/project.blade.php",
    "resources/views/app-development/partials/nav.blade.php",
    "resources/views/app-development/partials/icon.blade.php",
    "resources/views/app-development/partials/app-type-badges.blade.php",
    "resources/views/app-development/partials/app-type-picker.blade.php",
    "resources/views/app-development/partials/inline-app-types.blade.php",
    "resources/views/app-development/partials/task-status-badge.blade.php",
    "resources/views/app-development/partials/timer-widget.blade.php",
    "resources/views/app-development/partials/ticket-row.blade.php",
    "resources/views/app-development/tickets/index.blade.php",
    "resources/views/app-development/tickets/form-create.blade.php",
    "resources/views/app-development/tickets/form-edit.blade.php",
    "resources/views/app-development/tickets/panel.blade.php",
    "resources/views/app-development/tasks/index.blade.php",
    "resources/views/app-development/tasks/form-create.blade.php",
    "resources/views/app-development/tasks/panel.blade.php",
    "resources/views/app-development/reports/index.blade.php",
    "routes/app-development.php",
]


def load_sftp_config() -> tuple[str, str, str]:
    cfg = json.loads((LOCAL / ".vscode" / "sftp.json").read_text(encoding="utf-8"))
    return cfg["host"], cfg["username"], cfg["password"]


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
        print(resp.read().decode("utf-8", errors="replace")[:2000], flush=True)


def main() -> int:
    host, user, password = load_sftp_config()
    transport = paramiko.Transport((host, 22))
    transport.connect(username=user, password=password)
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
        if rel.startswith("public/"):
            public_rel = rel.removeprefix("public/")
            public_remote = f"{PUBLIC}/{public_rel}"
            ensure_dir(sftp, str(Path(public_remote).parent).replace("\\", "/"), cache)
            sftp.put(str(local), public_remote)
            print("uploaded public_html", public_rel, flush=True)

    sftp.close()
    transport.close()
    http_get(f"{SITE}/ops/migrate/483275634")
    http_get(f"{SITE}/ops/clear/483275634")
    return 0


if __name__ == "__main__":
    sys.exit(main())
