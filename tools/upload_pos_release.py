#!/usr/bin/env python3
"""Upload one POS release: previous binaries, new APK and setup, version stamps."""

from __future__ import annotations

import subprocess
import sys
import tempfile
import urllib.request
from pathlib import Path

import paramiko
from sftp_config import load_sftp

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
PUBLIC = "/home/tut8x1radmrp/public_html/kaman-workspace"
SITE = "https://kaman-workspace.com"
STAMP = "1.3.86.110"

APP_FILES = [
    "config/kaman-workspace.php",
    "resources/views/partials/pos-downloads.blade.php",
]

POS = Path(r"c:\Users\win\Documents\Flutter\Kaman Pos")
APK = POS / "build" / "app" / "outputs" / "flutter-apk" / "app-release.apk"
SETUP = POS / "build" / "windows" / "installer" / "KamanPOS-Setup.exe"


def ensure_dir(sftp: paramiko.SFTPClient, path: str, cache: set[str]) -> None:
    if path in cache or path in {"", "/"}:
        return
    cur = ""
    for part in path.strip("/").split("/"):
        cur += "/" + part
        if cur in cache:
            continue
        try:
            sftp.stat(cur)
        except FileNotFoundError:
            sftp.mkdir(cur)
        cache.add(cur)


def remote_size(sftp: paramiko.SFTPClient, path: str) -> int | None:
    try:
        return sftp.stat(path).st_size
    except FileNotFoundError:
        return None


def promote_previous(sftp: paramiko.SFTPClient, folder: str, current: str, previous: str, scratch: Path) -> None:
    src = f"{folder}/{current}"
    dst = f"{folder}/{previous}"
    size = remote_size(sftp, src)
    if size is None:
        print("missing remote", src, flush=True)
        return
    local = scratch / current
    print(f"keep {src} ({size} bytes) as {previous}", flush=True)
    sftp.get(src, str(local))
    sftp.put(str(local), dst)
    kept = remote_size(sftp, dst)
    print(f"previous {dst} {kept} bytes", flush=True)


def main() -> int:
    if not APK.is_file() or not SETUP.is_file():
        print("missing build", APK, APK.is_file(), SETUP, SETUP.is_file(), flush=True)
        return 1

    host, port, user, password = load_sftp()
    transport = paramiko.Transport((host, port))
    transport.connect(username=user, password=password)
    sftp = paramiko.SFTPClient.from_transport(transport)
    if sftp is None:
        print("sftp failed", flush=True)
        return 1

    cache: set[str] = set()
    downloads = f"{PUBLIC}/downloads"
    ensure_dir(sftp, downloads, cache)

    with tempfile.TemporaryDirectory() as tmp:
        scratch = Path(tmp)
        promote_previous(sftp, downloads, "kaman-pos.apk", "kaman-pos-previous.apk", scratch)
        promote_previous(sftp, downloads, "kaman-pos-setup.exe", "kaman-pos-previous-setup.exe", scratch)

    print(f"upload apk {APK.stat().st_size}", flush=True)
    sftp.put(str(APK), f"{downloads}/kaman-pos.apk")
    print(f"upload setup {SETUP.stat().st_size}", flush=True)
    sftp.put(str(SETUP), f"{downloads}/kaman-pos-setup.exe")

    for rel in APP_FILES:
        local = LOCAL / rel
        remote = f"{REMOTE}/{rel}"
        ensure_dir(sftp, remote.rsplit("/", 1)[0], cache)
        print("upload", rel, flush=True)
        sftp.put(str(local), remote)
        if rel.startswith("public/"):
            public_rel = rel[len("public/") :]
            public_path = f"{PUBLIC}/{public_rel}"
            ensure_dir(sftp, public_path.rsplit("/", 1)[0], cache)
            print("upload public", public_rel, flush=True)
            sftp.put(str(local), public_path)

    for name in ("kaman-pos.apk", "kaman-pos-setup.exe", "kaman-pos-previous.apk", "kaman-pos-previous-setup.exe"):
        print(name, remote_size(sftp, f"{downloads}/{name}"), flush=True)

    sftp.close()
    transport.close()

    clear = subprocess.run([sys.executable, str(LOCAL / "tools" / "ops_call.py"), "clear"], cwd=LOCAL)
    if clear.returncode != 0:
        return clear.returncode

    page = urllib.request.urlopen(
        urllib.request.Request(f"{SITE}/login", headers={"User-Agent": "Mozilla/5.0 KamanRelease"}),
        timeout=60,
    ).read().decode("utf-8", "replace")
    for needle in (
        f"kaman-pos.apk?v={STAMP}",
        f"kaman-pos-setup.exe?v={STAMP}",
        "kaman-pos-previous.apk?v=1.3.85.109",
        "kaman-pos-previous-setup.exe?v=1.3.85.109",
    ):
        print(("ok" if needle in page else "MISSING"), needle, flush=True)
        if needle not in page:
            return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
