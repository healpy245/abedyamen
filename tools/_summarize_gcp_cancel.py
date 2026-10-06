#!/usr/bin/env python3
from __future__ import annotations

import json
import re
from collections import Counter, defaultdict
from pathlib import Path

data = json.loads(Path(__file__).with_name("_gcp_live_audit.json").read_text(encoding="utf-8"))
out = Path(__file__).with_name("_gcp_cancel_summary.txt")
lines: list[str] = []


def p(*args) -> None:
    lines.append(" ".join(str(a) for a in args))


def walk_logs():
    feeds = [
        ("feed_gcp", data.get("feed_gcp") or {}),
        ("feed_bugs", data.get("feed_bugs") or {}),
        ("feed_api", data.get("feed_api") or {}),
    ]
    for name, feed in feeds:
        if not isinstance(feed, dict):
            continue
        yield name, feed, feed.get("live") or [], feed.get("recent") or [], feed.get("logs") or []
    devices = data.get("devices") or {}
    for did, payload in devices.items():
        if not isinstance(payload, dict):
            continue
        yield f"device:{did}", payload, payload.get("live") or [], payload.get("recent") or [], (payload.get("logs") or []) + (payload.get("recentLogs") or []) + (payload.get("history") or [])


def msg_of(log) -> str:
    return str(log.get("message") or log.get("msg") or "")


def ctx_of(log):
    return log.get("context") or {}


all_logs = []
seen = set()
p("==== DEVICES ====")
for name, feed, live, recent, logs in walk_logs():
    if name.startswith("feed") or name.startswith("device"):
        if "feed_gcp" == name or name.startswith("device"):
            for d in (live or []) + (recent or []):
                if not isinstance(d, dict):
                    continue
                p(
                    f"  [{name}] id={d.get('id')} cashier={d.get('cashierName')} role={d.get('role')} "
                    f"online={d.get('online')} v={d.get('appVersion')} failed={d.get('failedCount')} "
                    f"src={d.get('failedSource')} at={d.get('failedAt')}"
                )
                p("    last:", (d.get("failedMessage") or "")[:300])
    for log in logs:
        if not isinstance(log, dict):
            continue
        key = (log.get("id"), log.get("at"), log.get("message"))
        if key in seen:
            continue
        seen.add(key)
        all_logs.append(log)

p()
p("total unique logs", len(all_logs))
levels = Counter(str(l.get("level")) for l in all_logs)
p("levels", dict(levels))

errors = [l for l in all_logs if str(l.get("level")).lower() in {"error", "warn", "warning"}]
p("error/warn", len(errors))

cancel_re = re.compile(r"cancel", re.I)
status_404 = re.compile(r"\b404\b")
item_post = re.compile(r"POST .*/items\b", re.I)
put_item = re.compile(r"PUT .*/items/", re.I)
order_put = re.compile(r"PUT .*/orders/[^/]+(\s|->)", re.I)

p()
p("==== ERROR MESSAGES (grouped) ====")
err_groups = Counter()
for l in errors:
    m = msg_of(l)
    # collapse uuids
    norm = re.sub(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}", "{id}", m, flags=re.I)
    norm = re.sub(r"\d{10,}", "{n}", norm)
    err_groups[norm[:240]] += 1
for msg, n in err_groups.most_common(40):
    p(f"  {n:4d}  {msg}")

p()
p("==== CANCEL 404 SAMPLES ====")
cancels = []
for l in all_logs:
    m = msg_of(l)
    if cancel_re.search(m) and (status_404.search(m) or str(ctx_of(l).get("status")) == "404"):
        cancels.append(l)
p("cancel+404 count", len(cancels))
by_path = Counter()
by_device = Counter()
by_level = Counter()
for l in cancels:
    m = msg_of(l)
    norm = re.sub(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}", "{id}", m, flags=re.I)
    by_path[norm[:220]] += 1
    by_level[str(l.get("level"))] += 1
    by_device[str(l.get("deviceId") or l.get("device_id") or l.get("cashierName") or l.get("source"))] += 1
p("levels", dict(by_level))
p("devices", dict(by_device))
for msg, n in by_path.most_common(20):
    p(f"  {n:4d}  {msg}")
p("sample raw:")
for l in cancels[:12]:
    p(" -", l.get("at"), l.get("level"), l.get("source"), msg_of(l)[:280], "ctx", ctx_of(l))

p()
p("==== ITEM POST / PUT FAILURES ====")
item_fails = []
for l in errors:
    m = msg_of(l)
    if item_post.search(m) or put_item.search(m) or "/items" in m:
        item_fails.append(l)
p("item-related errors", len(item_fails))
item_g = Counter()
for l in item_fails:
    m = re.sub(r"[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}", "{id}", msg_of(l), flags=re.I)
    item_g[m[:240]] += 1
for msg, n in item_g.most_common(25):
    p(f"  {n:4d}  {msg}")
p("item samples:")
for l in item_fails[:15]:
    p(" -", l.get("at"), l.get("cashierName") or l.get("device"), msg_of(l)[:320])

p()
p("==== WAITER / MALTSAR HINTS ====")
for l in all_logs:
    m = msg_of(l).lower()
    cashier = str(l.get("cashierName") or "")
    role = str(l.get("role") or l.get("deviceRole") or "")
    if any(x in (cashier + role + m) for x in ["מלצר", "waiter", "حمودي", "maltsar", "nadel"]):
        if str(l.get("level")).lower() in {"error", "warn", "warning"} or "422" in msg_of(l) or "404" in msg_of(l):
            p(" -", l.get("at"), cashier, role, l.get("level"), msg_of(l)[:300])

p()
p("==== RECENT ERROR TAIL ====")
# sort if possible
def at_key(l):
    return str(l.get("at") or "")

for l in sorted(errors, key=at_key)[-40:]:
    p(l.get("at"), l.get("cashierName") or "", l.get("level"), msg_of(l)[:260])

out.write_text("\n".join(lines), encoding="utf-8")
print("WROTE", out, "logs", len(all_logs), "errors", len(errors), "cancel404", len(cancels))
