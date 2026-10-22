#!/usr/bin/env python3
"""Upload Kaman POS Customer Support (phase 1) and migrate/seed/clear."""

from __future__ import annotations

import secrets
import ssl
import sys
import urllib.request
from pathlib import Path

import paramiko
from sftp_config import load_sftp

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
PUBLIC = "/home/tut8x1radmrp/public_html/kaman-workspace"

HOST, _SFTP_PORT, USER, PASSWORD = load_sftp()
SITE = "https://kaman-workspace.com"
SECRET_FILE = LOCAL / ".kaman-support.env"

FILES = [
    "app/Enums/Project.php",
    "app/Enums/KamanSupport/SupportCommandName.php",
    "app/Enums/KamanSupport/SupportCommandStatus.php",
    "app/Enums/KamanSupport/SupportTicketEventType.php",
    "app/Enums/KamanSupport/SupportTicketStatus.php",
    "app/Http/Controllers/Api/KamanSupport/DeviceSessionController.php",
    "app/Http/Requests/KamanSupport/StoreDeviceLogsRequest.php",
    "app/Http/Requests/KamanSupport/StoreDeviceSessionRequest.php",
    "app/Models/KamanSupport/SupportDeviceCommand.php",
    "app/Models/KamanSupport/SupportDeviceLog.php",
    "app/Services/KamanSupport/SupportDeviceCommandService.php",
    "app/Services/KamanSupport/SupportDeviceSessionService.php",
    "database/migrations/2026_09_20_150000_add_live_logs_to_support_devices.php",
    "database/migrations/2026_09_21_113000_create_support_device_commands_table.php",
    "public/css/kaman.css",
    "public/js/kaman-support-live.js",
    "resources/views/kaman-support/device.blade.php",
    "resources/views/kaman-support/failed.blade.php",
    "resources/views/kaman-support/live.blade.php",
    "resources/views/kaman-support/partials/command-form.blade.php",
    "resources/views/kaman-support/partials/device-card.blade.php",
    "resources/views/kaman-support/partials/log-row.blade.php",
    "resources/views/kaman-support/partials/nav.blade.php",
    "app/Http/Controllers/Api/KamanSupport/HealthController.php",
    "app/Http/Controllers/Api/KamanSupport/TicketCommandController.php",
    "app/Http/Controllers/Api/KamanSupport/TicketController.php",
    "app/Http/Controllers/KamanSupport/ConsoleController.php",
    "app/Http/Middleware/KamanSupport/AuthenticateSupportKey.php",
    "app/Http/Middleware/KamanSupport/HandleSupportCors.php",
    "app/Http/Middleware/KamanSupport/LimitSupportPayload.php",
    "app/Http/Requests/KamanSupport/StorePosTicketRequest.php",
    "app/Http/Requests/KamanSupport/StoreSupportTicketCommandRequest.php",
    "app/Http/Requests/KamanSupport/StoreSupportTicketEventRequest.php",
    "app/Http/Requests/KamanSupport/UpdateSupportTicketRequest.php",
    "app/Http/Resources/KamanSupport/SupportTicketResource.php",
    "app/Models/KamanSupport/SupportDevice.php",
    "app/Models/KamanSupport/SupportTicket.php",
    "app/Models/KamanSupport/SupportTicketEvent.php",
    "app/Models/KamanSupport/SupportWorker.php",
    "app/Models/User.php",
    "app/Providers/AppServiceProvider.php",
    "app/Services/KamanSupport/SupportTicketIdGenerator.php",
    "app/Services/KamanSupport/SupportTicketService.php",
    "bootstrap/app.php",
    "config/cors.php",
    "config/kaman-support.php",
    "database/migrations/2026_09_19_010000_create_kaman_support_tables.php",
    "database/seeders/DatabaseSeeder.php",
    "database/seeders/KamanSupportWorkerSeeder.php",
    "database/seeders/WorkspaceUserSeeder.php",
    "kaman-support/API.md",
    "lang/ar/kaman-support.php",
    "lang/ar/projects.php",
    "lang/en/kaman-support.php",
    "lang/en/projects.php",
    "lang/he/kaman-support.php",
    "lang/he/projects.php",
    "resources/views/components/workspace/icon.blade.php",
    "resources/views/kaman-support/index.blade.php",
    "resources/views/kaman-support/show.blade.php",
    "routes/api.php",
    "routes/kaman-support.php",
    "routes/web.php",
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


def load_or_create_secret() -> str:
    if SECRET_FILE.is_file():
        for line in SECRET_FILE.read_text(encoding="utf-8").splitlines():
            if line.startswith("KAMAN_SUPPORT_API_KEY="):
                value = line.split("=", 1)[1].strip().strip('"').strip("'")
                if value:
                    return value
    key = secrets.token_urlsafe(36)
    SECRET_FILE.write_text(
        "# Gitignored. POS sends this as X-Kaman-Support-Key.\n"
        f"KAMAN_SUPPORT_API_KEY={key}\n"
        "# Console login: existing workspace user yamen@kaman.rest at /login\n"
        "# (workspace password is not stored here)\n",
        encoding="utf-8",
    )
    print("wrote", SECRET_FILE, flush=True)
    return key


def ensure_remote_env(key: str) -> None:
    client = paramiko.SSHClient()
    client.set_missing_host_key_policy(paramiko.AutoAddPolicy())
    client.connect(HOST, username=USER, password=PASSWORD, timeout=20)
    remote_env = f"{REMOTE}/.env"
    cmd = (
        "python3 - <<'PY'\n"
        "from pathlib import Path\n"
        f"path = Path({remote_env!r})\n"
        f"key = {key!r}\n"
        "text = path.read_text(encoding='utf-8', errors='replace') if path.exists() else ''\n"
        "lines = text.splitlines()\n"
        "found = False\n"
        "out = []\n"
        "for line in lines:\n"
        "    if line.startswith('KAMAN_SUPPORT_API_KEY='):\n"
        "        current = line.split('=', 1)[1].strip().strip('\"').strip(\"'\")\n"
        "        if current:\n"
        "            out.append(line)\n"
        "        else:\n"
        "            out.append('KAMAN_SUPPORT_API_KEY=' + key)\n"
        "        found = True\n"
        "    else:\n"
        "        out.append(line)\n"
        "if not found:\n"
        "    if out and out[-1] != '':\n"
        "        out.append('')\n"
        "    out.append('KAMAN_SUPPORT_API_KEY=' + key)\n"
        "    out.append('')\n"
        "path.write_text('\\n'.join(out) + ('\\n' if out else ''), encoding='utf-8')\n"
        "print('env_key_present')\n"
        "PY"
    )
    print("SSH ensure remote .env key", flush=True)
    _stdin, stdout, stderr = client.exec_command(cmd, timeout=60)
    print(stdout.read().decode("utf-8", errors="replace"), flush=True)
    err = stderr.read().decode("utf-8", errors="replace")
    if err.strip():
        print("SSH env stderr", err[-1500:], flush=True)

    artisan = (
        "cd /home/tut8x1radmrp/webtimize && "
        "php artisan migrate --force && "
        "php artisan db:seed --class=KamanSupportWorkerSeeder --force && "
        "php artisan optimize:clear"
    )
    print("SSH", artisan, flush=True)
    _stdin, stdout, stderr = client.exec_command(artisan, timeout=180)
    out = stdout.read().decode("utf-8", errors="replace")
    err = stderr.read().decode("utf-8", errors="replace")
    print(out[-4000:], flush=True)
    if err.strip():
        print("SSH artisan stderr", err[-2000:], flush=True)
    client.close()
    if "Shell access is not enabled" in out + err:
        raise RuntimeError("ssh shell disabled")


def main() -> int:
    key = load_or_create_secret()
    local_env = LOCAL / ".env"
    if local_env.is_file() and "KAMAN_SUPPORT_API_KEY=" not in local_env.read_text(encoding="utf-8", errors="replace"):
        with local_env.open("a", encoding="utf-8") as fh:
            fh.write(f"\nKAMAN_SUPPORT_API_KEY={key}\n")
        print("appended key to local .env", flush=True)

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
        if rel.startswith("public/"):
            public_rel = rel.removeprefix("public/")
            public_remote = f"{PUBLIC}/{public_rel}"
            ensure_dir(sftp, str(Path(public_remote).parent).replace("\\", "/"), cache)
            sftp.put(str(local), public_remote)
            print("uploaded public_html", public_rel, flush=True)

    sftp.close()
    transport.close()
    print("SFTP done", len(FILES), "files", flush=True)

    try:
        ensure_remote_env(key)
    except Exception as exc:
        print("SSH unavailable:", type(exc).__name__, exc, flush=True)
        print("Falling back to ops HTTP migrate/clear", flush=True)
        http_get(f"{SITE}/ops/migrate/483275634")
        http_get(f"{SITE}/ops/clear/483275634")

    http_get(f"{SITE}/api/kaman-support/v1/health")
    return 0


if __name__ == "__main__":
    sys.exit(main())
