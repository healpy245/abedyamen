"""Load SFTP credentials from the gitignored local config."""

from __future__ import annotations

import json
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CONFIG = ROOT / ".vscode" / "sftp.json"


def load_sftp() -> tuple[str, int, str, str]:
    if not CONFIG.is_file():
        raise SystemExit("Missing .vscode/sftp.json. Put the hosting login there, not in a script.")

    cfg = json.loads(CONFIG.read_text(encoding="utf-8"))
    host = str(cfg.get("host", "")).strip()
    user = str(cfg.get("username", "")).strip()
    password = str(cfg.get("password", ""))
    port = int(cfg.get("port", 22))
    if host == "" or user == "" or password == "":
        raise SystemExit("SFTP host, username, or password is missing from .vscode/sftp.json.")

    return host, port, user, password
