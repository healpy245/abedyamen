# -*- coding: utf-8 -*-
import json
import urllib.request
import ssl

ctx = ssl.create_default_context()
ctx.check_hostname = False
ctx.verify_mode = ssl.CERT_NONE

BASE = "https://thex.kaman.dev/api/manager"

with open(__file__.replace("kaman_fetch_state.py", "login_body.json"), encoding="utf-8") as f:
    login_body = f.read()

req = urllib.request.Request(
    f"{BASE}/login",
    data=login_body.encode("utf-8"),
    headers={"Content-Type": "application/json", "Accept": "application/json"},
    method="POST",
)
with urllib.request.urlopen(req, context=ctx) as resp:
    login = json.loads(resp.read().decode())
token = login["data"]["token"]

out = {"token_prefix": token[:12] + "..."}


def get(path):
    req = urllib.request.Request(
        f"{BASE}/{path}",
        headers={"Authorization": f"Bearer {token}", "Accept": "application/json"},
    )
    with urllib.request.urlopen(req, context=ctx) as resp:
        return json.loads(resp.read().decode())


for path in ["ingredients", "items", "inventory-items", "suppliers", "ingredients-categories"]:
    try:
        data = get(path)
        payload = data.get("data", data)
        if isinstance(payload, list):
            out[path] = {"count": len(payload), "sample": payload[:2]}
        elif isinstance(payload, dict) and "data" in payload:
            inner = payload["data"]
            out[path] = {"count": len(inner) if isinstance(inner, list) else payload, "sample": inner[:2] if isinstance(inner, list) else inner}
        else:
            out[path] = {"raw_keys": list(data.keys()), "sample": str(payload)[:800]}
    except Exception as e:
        out[path] = {"error": str(e)}

Path = __import__("pathlib").Path
out_path = Path(__file__).parent / "kaman_state.json"
out_path.write_text(json.dumps(out, ensure_ascii=False, indent=2), encoding="utf-8")
print(f"Wrote {out_path}")
