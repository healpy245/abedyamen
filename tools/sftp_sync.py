#!/usr/bin/env python3
"""Parallel SFTP sync of local Laravel project to production."""

from __future__ import annotations

import os
import sys
import time
from concurrent.futures import ThreadPoolExecutor, as_completed
from pathlib import Path
from threading import Lock

import paramiko

LOCAL = Path(__file__).resolve().parents[1]
REMOTE = "/home/tut8x1radmrp/webtimize"
HOST = "sg2plzcpnl505722.prod.sin2.secureserver.net"
USER = "tut8x1radmrp"
PASSWORD = "Mfit2025@"
WORKERS = 6

DIR_IGNORE = {".vscode", ".git", ".idea", ".claude", "node_modules", "vendor"}
FILE_IGNORE = {
    ".env",
    ".env.backup",
    ".env.production",
    ".DS_Store",
    ".phpunit.result.cache",
    "resources.zip",
    "Homestead.json",
    "Homestead.yaml",
    "auth.json",
    "sftp_sync.py",
    "database.sqlite",
}
PATH_PREFIX_IGNORE = (
    "storage/logs",
    "storage/framework/cache",
    "storage/framework/sessions",
    "storage/framework/views",
    "storage/pail",
    "public/hot",
    "public/storage",
    "public/full-ai-sessions",
    "tools",
)


def to_posix(rel: str) -> str:
    return rel.replace("\\", "/")


def should_ignore(rel: str) -> bool:
    rel_posix = to_posix(rel)
    parts = rel_posix.split("/")
    if any(p in DIR_IGNORE for p in parts):
        return True
    if parts[-1] in FILE_IGNORE:
        return True
    for pref in PATH_PREFIX_IGNORE:
        if rel_posix == pref or rel_posix.startswith(pref + "/"):
            return True
    return False


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


def collect_files() -> list[tuple[Path, str]]:
    items: list[tuple[Path, str]] = []
    for root, dirs, files in os.walk(LOCAL):
        rel_root = os.path.relpath(root, LOCAL)
        if rel_root == ".":
            rel_root = ""

        keep = []
        for dirname in dirs:
            rel = f"{rel_root}/{dirname}" if rel_root else dirname
            if not should_ignore(rel):
                keep.append(dirname)
        dirs[:] = keep

        if rel_root and should_ignore(rel_root):
            continue

        for name in files:
            rel = f"{rel_root}/{name}" if rel_root else name
            if should_ignore(rel):
                continue
            items.append((LOCAL / rel, to_posix(rel)))
    return items


def worker(files: list[tuple[Path, str]], counter: dict, lock: Lock) -> list[str]:
    errors: list[str] = []
    transport = paramiko.Transport((HOST, 22))
    transport.use_compression(True)
    transport.connect(username=USER, password=PASSWORD)
    sftp = paramiko.SFTPClient.from_transport(transport)
    assert sftp is not None
    dir_cache: set[str] = set()

    for local_path, rel in files:
        remote_path = f"{REMOTE}/{rel}"
        try:
            lstat = local_path.stat()
            try:
                rstat = sftp.stat(remote_path)
                if rstat.st_size == lstat.st_size:
                    with lock:
                        counter["skipped"] += 1
                    continue
            except FileNotFoundError:
                pass

            ensure_dir(sftp, os.path.dirname(remote_path).replace("\\", "/"), dir_cache)
            sftp.put(str(local_path), remote_path)
            with lock:
                counter["uploaded"] += 1
                n = counter["uploaded"]
                if n % 40 == 0:
                    print(f"uploaded {n} ... last {rel}", flush=True)
        except Exception as exc:  # noqa: BLE001
            errors.append(f"{rel}: {exc}")

    sftp.close()
    transport.close()
    return errors


def main() -> int:
    files = collect_files()
    print(f"files to consider: {len(files)} workers={WORKERS}", flush=True)
    start = time.time()
    counter = {"uploaded": 0, "skipped": 0}
    lock = Lock()
    chunks: list[list[tuple[Path, str]]] = [[] for _ in range(WORKERS)]
    for i, item in enumerate(files):
        chunks[i % WORKERS].append(item)

    errors: list[str] = []
    with ThreadPoolExecutor(max_workers=WORKERS) as pool:
        futures = [pool.submit(worker, chunk, counter, lock) for chunk in chunks if chunk]
        for fut in as_completed(futures):
            errors.extend(fut.result())

    elapsed = time.time() - start
    print(
        f"DONE uploaded={counter['uploaded']} skipped={counter['skipped']} "
        f"errors={len(errors)} elapsed={elapsed:.1f}s",
        flush=True,
    )
    for err in errors[:40]:
        print("ERROR:", err, flush=True)
    return 1 if errors else 0


if __name__ == "__main__":
    sys.exit(main())
