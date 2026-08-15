#!/usr/bin/env python3
"""Upload Vite build + static CSS assets to the domain document root."""

from __future__ import annotations

import os
import sys
from pathlib import Path

import paramiko

LOCAL_PUBLIC = Path(__file__).resolve().parents[1] / "public"
REMOTE = "/home/tut8x1radmrp/public_html/kaman-workspace"
HOST = "sg2plzcpnl505722.prod.sin2.secureserver.net"
USER = "tut8x1radmrp"
PASSWORD = "Mfit2025@"

# Relative paths under public/ that the domain docroot must serve.
UPLOAD_PATHS = [
    "build",
    "css",
    "js",
    "kaman.png",
    "favicon.ico",
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
        try:
            sftp.chmod(cur, 0o755)
        except OSError:
            pass
        cache.add(cur)


def upload_tree(sftp: paramiko.SFTPClient, local: Path, remote: str, cache: set[str]) -> int:
    uploaded = 0
    if local.is_file():
        ensure_dir(sftp, os.path.dirname(remote).replace("\\", "/"), cache)
        sftp.put(str(local), remote)
        try:
            sftp.chmod(remote, 0o644)
        except OSError:
            pass
        print(f"uploaded {remote}", flush=True)
        return 1

    for root, _dirs, files in os.walk(local):
        rel = os.path.relpath(root, local)
        remote_dir = remote if rel == "." else f"{remote}/{rel.replace(chr(92), '/')}"
        ensure_dir(sftp, remote_dir, cache)
        for name in files:
            local_path = Path(root) / name
            remote_path = f"{remote_dir}/{name}"
            sftp.put(str(local_path), remote_path)
            try:
                sftp.chmod(remote_path, 0o644)
            except OSError:
                pass
            uploaded += 1
            print(f"uploaded {remote_path}", flush=True)
    return uploaded


def main() -> int:
    transport = paramiko.Transport((HOST, 22))
    transport.connect(username=USER, password=PASSWORD)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None

    cache: set[str] = set()
    ensure_dir(sftp, REMOTE, cache)

    assets_dir = f"{REMOTE}/build/assets"
    try:
        for name in sftp.listdir(assets_dir):
            sftp.remove(f"{assets_dir}/{name}")
            print(f"removed old {name}", flush=True)
    except FileNotFoundError:
        pass

    total = 0
    for rel in UPLOAD_PATHS:
        local = LOCAL_PUBLIC / rel
        if not local.exists():
            print(f"skip missing {rel}", flush=True)
            continue
        total += upload_tree(sftp, local, f"{REMOTE}/{rel}", cache)

    for path in [REMOTE, f"{REMOTE}/css", f"{REMOTE}/build", f"{REMOTE}/build/assets"]:
        try:
            sftp.chmod(path, 0o755)
        except OSError:
            pass

    sftp.close()
    transport.close()
    print(f"DONE uploaded={total} -> {REMOTE}", flush=True)
    return 0


if __name__ == "__main__":
    sys.exit(main())
