#!/usr/bin/env python3
from __future__ import annotations

import json
import ssl
import urllib.request
from pathlib import Path

import paramiko

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
PUBLIC_REMOTE = "/home/tut8x1radmrp/public_html/kaman-workspace"
SITE = "https://kaman-workspace.com"
SFTP_CFG = json.loads((LOCAL / ".vscode" / "sftp.json").read_text(encoding="utf-8"))

FILES = [
    "app/Models/FormWorkflowRun.php",
    "app/Services/Form/FormWorkflowRunService.php",
    "app/Http/Controllers/FormController.php",
    "routes/web.php",
    "database/migrations/2026_09_08_151000_add_events_to_form_workflow_runs_table.php",
    "lang/en/form.php",
    "lang/ar/form.php",
    "lang/he/form.php",
    "resources/views/form.blade.php",
    "public/css/kaman.css",
]
PUBLIC_FILES = [
    "public/css/kaman.css",
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
        body = resp.read().decode("utf-8", errors="replace")
        print("status", resp.status, flush=True)
        print(body[:2000], flush=True)


def main() -> int:
    transport = paramiko.Transport((SFTP_CFG["host"], int(SFTP_CFG.get("port", 22))))
    transport.connect(username=SFTP_CFG["username"], password=SFTP_CFG["password"])
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

    for rel in PUBLIC_FILES:
        local = LOCAL / rel
        if not local.is_file():
            print("MISSING public", rel, flush=True)
            continue
        public_rel = rel[len("public/") :] if rel.startswith("public/") else rel
        remote = f"{PUBLIC_REMOTE}/{public_rel}"
        ensure_dir(sftp, str(Path(remote).parent).replace("\\", "/"), cache)
        sftp.put(str(local), remote)
        print("uploaded public", public_rel, flush=True)

    sftp.close()
    transport.close()
    print("SFTP done", len(FILES), "files", flush=True)
    http_get(f"{SITE}/ops/migrate/483275634")
    http_get(f"{SITE}/ops/clear/483275634")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
