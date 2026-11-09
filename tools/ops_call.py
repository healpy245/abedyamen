"""Call a live /ops/ route with the X-Ops-Key header.

    python tools/ops_call.py clear
    python tools/ops_call.py migrate
    python tools/ops_call.py malan-whatsapp-status --get
    python tools/ops_call.py malan-campaign-debug/1 stop_loops=1

The key comes from the OPS_LIVE_KEY environment variable, or the same name in the local .env.
"""
from __future__ import annotations

import os
import ssl
import sys
import urllib.error
import urllib.parse
import urllib.request
from pathlib import Path

SITE = "https://kaman-workspace.com"


def live_key() -> str:
    key = os.environ.get("OPS_LIVE_KEY", "").strip()
    if key:
        return key
    env_file = Path(__file__).resolve().parent.parent / ".env"
    if env_file.is_file():
        for line in env_file.read_text(encoding="utf-8").splitlines():
            if line.startswith("OPS_LIVE_KEY="):
                return line.split("=", 1)[1].strip().strip("\"'")
    return ""


def ops_call(path: str, fields: dict[str, str] | None = None, method: str = "POST", timeout: int = 180) -> int:
    key = live_key()
    if not key:
        raise SystemExit("OPS_LIVE_KEY is not set (environment or local .env).")

    url = f"{SITE}/ops/{path.strip('/')}"
    data = None
    if method == "POST":
        data = urllib.parse.urlencode(fields or {}).encode("utf-8")
    elif fields:
        url += "?" + urllib.parse.urlencode(fields)

    print(method, url, flush=True)
    req = urllib.request.Request(url, data=data, method=method, headers={"User-Agent": "deploy-bot", "X-Ops-Key": key})
    try:
        with urllib.request.urlopen(req, timeout=timeout, context=ssl.create_default_context()) as resp:
            print("status", resp.status, flush=True)
            print(resp.read().decode("utf-8", errors="replace")[:4000], flush=True)
            return resp.status
    except urllib.error.HTTPError as exc:
        print("status", exc.code, flush=True)
        print(exc.read().decode("utf-8", errors="replace")[:4000], flush=True)
        return exc.code


def main() -> int:
    args = sys.argv[1:]
    method = "GET" if "--get" in args else "POST"
    args = [a for a in args if a != "--get"]
    if not args:
        raise SystemExit(__doc__)

    fields = dict(a.split("=", 1) for a in args[1:])
    status = ops_call(args[0], fields, method)
    return 0 if status < 400 else 1


if __name__ == "__main__":
    sys.exit(main())
