#!/usr/bin/env python3
"""Upload the Kaman customer APK and its token-gated download route."""

from __future__ import annotations

import json
import ssl
import sys
import urllib.request
from pathlib import Path

import paramiko

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
SITE = "https://kaman-workspace.com"

FILES = [
    "config/kaman-app.php",
    "app/Http/Controllers/Api/KamanApp/ApkDownloadController.php",
    "app/Http/Controllers/Api/KamanApp/SharedCartController.php",
    "routes/api.php",
    "routes/web.php",
    "resources/views/welcome.blade.php",
    "resources/views/partials/client-downloads.blade.php",
    "lang/en/app.php",
    "lang/ar/app.php",
    "lang/he/app.php",
    "lang/en/kaman-support.php",
    "lang/ar/kaman-support.php",
    "lang/he/kaman-support.php",
    "app/Models/KamanSupport/SupportDevice.php",
    "app/Http/Controllers/KamanSupport/ConsoleController.php",
    "app/Services/KamanSupport/SupportDeviceSessionService.php",
    "resources/views/kaman-support/live.blade.php",
    "resources/views/kaman-support/device.blade.php",
]


def load_sftp_config() -> tuple[str, str, str]:
    cfg = json.loads((LOCAL / ".vscode" / "sftp.json").read_text(encoding="utf-8"))
    return cfg["host"], cfg["username"], cfg["password"]


def ensure_remote_dir(sftp: paramiko.SFTPClient, remote_dir: str) -> None:
    parts = remote_dir.strip("/").split("/")
    path = ""
    for part in parts:
        path += "/" + part
        try:
            sftp.stat(path)
        except FileNotFoundError:
            sftp.mkdir(path)


def upload(sftp: paramiko.SFTPClient, local: Path, remote: str) -> None:
    ensure_remote_dir(sftp, str(Path(remote).parent).replace("\\", "/"))
    sftp.put(str(local), remote)
    print(f"uploaded {remote}")


def hit(path: str) -> None:
    url = f"{SITE}{path}"
    ctx = ssl.create_default_context()
    req = urllib.request.Request(url, headers={"User-Agent": "deploy-bot"})
    with urllib.request.urlopen(req, context=ctx, timeout=120) as resp:
        body = resp.read(200).decode("utf-8", errors="replace")
        print(f"GET {path} -> {resp.status} {body[:120]!r}")


def main() -> int:
    apk = Path(sys.argv[1]) if len(sys.argv) == 2 else None
    if apk is not None and not apk.is_file():
        print(f"missing apk: {apk}", file=sys.stderr)
        return 2

    host, user, password = load_sftp_config()
    transport = paramiko.Transport((host, 22))
    transport.connect(username=user, password=password)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    try:
        for rel in FILES:
            upload(sftp, LOCAL / rel, f"{REMOTE}/{rel}")
        if apk is not None:
            upload(sftp, apk, f"{REMOTE}/storage/app/private/kaman-app.apk")
    finally:
        sftp.close()
        transport.close()

    hit("/ops/clear/483275634")
    return 0


if __name__ == "__main__":
    sys.exit(main())
