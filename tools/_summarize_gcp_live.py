#!/usr/bin/env python3
from __future__ import annotations

import json
from collections import Counter, defaultdict
from pathlib import Path

data = json.loads(Path(__file__).with_name("_gcp_live_audit.json").read_text(encoding="utf-8"))
out = Path(__file__).with_name("_gcp_live_summary.txt")
lines: list[str] = []


def p(*args) -> None:
    lines.append(" ".join(str(a) for a in args))


def summarize_feed(name: str, feed) -> None:
    p("====", name, "====")
    if not isinstance(feed, dict):
        p("not json")
        return
    live = feed.get("live") or []
    recent = feed.get("recent") or []
    p("live", len(live), "recent", len(recent))
    for device in live + recent:
        p(
            f"  id={device.get('id')} {device.get('cashierName')} {device.get('role')} "
            f"online={device.get('online')} v={device.get('appVersion')} "
            f"failed={device.get('failedCount')} src={device.get('failedSource')} at={device.get('failedAt')}"
        )
        p("   msg", (device.get("failedMessage") or "")[:240])
    logs = feed.get("logs") or []
    p("logs", len(logs))
    counts: Counter[tuple[str, str]] = Counter()
    for log in logs:
        if log.get("level") == "error" or log.get("failed"):
            counts[(str(log.get("source")), (log.get("message") or "")[:180])] += 1
    for (src, msg), n in counts.most_common(40):
        p(f"  {n}x [{src}] {msg}")


summarize_feed("feed_gcp", data["feed_gcp"])
summarize_feed("feed_bugs", data["feed_bugs"])
summarize_feed("feed_api", data["feed_api"])

p("\n==== DEVICE LOGS ====")
for did, payload in data["devices"].items():
    device = payload.get("device") or {}
    logs = payload.get("logs") or []
    p(
        f"\nDEVICE {did} {device.get('cashierName')} {device.get('role')} "
        f"failed={device.get('failedCount')} logs={len(logs)}"
    )
    counts: Counter[tuple[str, str]] = Counter()
    samples: dict[tuple[str, str], list] = defaultdict(list)
    for log in logs:
        if log.get("level") != "error" and not log.get("failed"):
            continue
        key = (str(log.get("source")), (log.get("message") or "")[:200])
        counts[key] += 1
        if len(samples[key]) < 2:
            samples[key].append({"at": log.get("at"), "ctx": log.get("context")})
    if not counts:
        p("  no error logs in live tail")
        for log in logs[:8]:
            p("  last", log.get("level"), log.get("source"), (log.get("message") or "")[:160], log.get("at"))
        continue
    for key, n in counts.most_common(50):
        src, msg = key
        p(f"  {n}x [{src}] {msg}")
        for sample in samples[key]:
            ctx = json.dumps(sample["ctx"], ensure_ascii=False)[:300] if sample["ctx"] else None
            p("     at", sample["at"], "ctx", ctx)

out.write_text("\n".join(lines), encoding="utf-8")
print("WROTE", out)
