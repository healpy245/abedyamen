# Kaman POS Customer Support API

Base URL: `https://kaman-workspace.com/api/kaman-support/v1`

JSON only. UTF-8. The cloud never scans restaurant LANs — the POS tablet is the only machine that can ping `10.x` printers.

## POS authentication (phase 1)

```
X-Kaman-Support-Key: <shared secret>
```

The secret lives in server `.env` as `KAMAN_SUPPORT_API_KEY` (also copied to the gitignored local file `.kaman-support.env`). Do not commit it. Device-signed tokens replace this later.

## Worker console

- Login: existing workspace login at `https://kaman-workspace.com/login`
- Console: `https://kaman-workspace.com/kaman-support/`
- Live tablet / POS logs: `https://kaman-workspace.com/kaman-support/live`
- Phase 1 access: Yamen workspace user only (`yamen@kaman.rest`)
- Worker JSON routes on the same `/api/kaman-support/v1` path require that session (not the POS key)

## Routes

| Method | Path | Who | Notes |
|---|---|---|---|
| GET | `/health` | public | `{ "ok": true, "service": "kaman-support" }` |
| POST | `/tickets` | POS key | Create or idempotent replay |
| GET | `/tickets` | worker session | `status`, `restaurant`, `q`, `page`, `limit` |
| GET | `/tickets/{id}` | worker session | Full ticket + events |
| PATCH | `/tickets/{id}` | worker session | `status`, `assignee`, `note` |
| POST | `/tickets/{id}/events` | worker session | `type`: `note\|diagnosis\|command_result` |
| POST | `/tickets/{id}/commands` | worker session | Queue one allowlisted job for the ticket's POS |
| GET | `/devices/session` | POS key | Live protocol info |
| POST | `/devices/session` | POS key | Heartbeat + optional log batch |
| POST | `/devices/logs` | POS key | Log batch only |
| POST | `/devices/close` | POS key | Mark the POS app closed |

Statuses: `open` · `in_progress` · `waiting_restaurant` · `resolved` · `closed`

Ticket id: keep `clientTicketId` (e.g. `KAM-A3F2B1`) when sent. Otherwise the server generates `KAM-` + 6 uppercase alphanumeric. Same `clientTicketId` + `restaurant` is idempotent (existing ticket is returned, not duplicated). `summary` is required; all other fields are optional. Unknown JSON keys are stored in `extra`.

Rate limit on `POST /tickets`: 30/min per IP and per restaurant. Max body ≈ 256KB.

## POS: create a ticket

```bash
curl -sS -X POST "https://kaman-workspace.com/api/kaman-support/v1/tickets" \
  -H "Content-Type: application/json; charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-Kaman-Support-Key: $KAMAN_SUPPORT_API_KEY" \
  -d '{
    "clientTicketId": "KAM-A3F2B1",
    "createdAt": "2026-09-19T01:00:00.000Z",
    "summary": "طلب 45 لا يطبع على جريل حسب أنواع المطبخ في الـ CRM",
    "details": "full diagnostic dump / AI summary",
    "restaurant": "kaman",
    "restaurantName": "Kaman",
    "cashierName": "Ali",
    "deviceId": "optional-pos-device-id",
    "locale": "ar",
    "conversation": ["user: الطابعه الخاصه بالجريل ما طبعت", "ticket: KAM-A3F2B1"],
    "diagnosis": {
      "cause": "noMatchingKitchenType",
      "printer": "جريل",
      "ip": "10.0.0.3",
      "reachable": true,
      "order": 45,
      "routedItems": "",
      "otherItems": "1× سلطة (مطبخ)",
      "logs": ["..."],
      "shouldOpenTicket": true
    },
    "printers": [
      {
        "name": "جريل",
        "role": "kitchen",
        "station": "جريل",
        "stationId": "k-grill",
        "ip": "10.0.0.3:9100",
        "crmOnly": false,
        "issue": "none"
      }
    ]
  }'
```

201 = created. 200 = same `clientTicketId` already existed for that restaurant.

Health check:

```bash
curl -sS "https://kaman-workspace.com/api/kaman-support/v1/health"
```

## Live POS sessions and logs

`POST /devices/session` with the POS key upserts the open app (restaurant, cashier, platform, `deviceKind`, version) and may include a `logs` batch. Android/iOS devices are tablets; Windows/macOS/Linux are desktop POS. Each `deviceId` keeps its own live tail and stored history. Devices counted as open if `appState != closed` and `lastSeenAt` is within 90 seconds. Yamen reads them at `/kaman-support/live` (Devices, All logs, All bugs, Failed API, Full debug). All logs streams both local app sources and API calls. Failed API is a live filter for error-level requests, with quick hint chips for status codes and paths. Tap a tablet card to open that device only.

```bash
curl -sS -X POST "https://kaman-workspace.com/api/kaman-support/v1/devices/session" \
  -H "Content-Type: application/json; charset=utf-8" \
  -H "X-Kaman-Support-Key: $KAMAN_SUPPORT_API_KEY" \
  -d '{
    "deviceId": "pos-device-1",
    "restaurant": "kaman",
    "restaurantName": "Kaman",
    "cashierName": "Ali",
    "platform": "android",
    "deviceKind": "tablet",
    "appVersion": "1.3.15+38",
    "loggedIn": true,
    "appState": "open",
    "logs": [{"id":"log-1","level":"info","source":"auth","message":"signed in"}]
  }'
```

The worker console at `/kaman-support/live` is a live printer-settings page. It does not reload. The worker feed marks the tablet as watched; the next heartbeats run every ~1.5s and carry the current printer layout. Jobs still execute on the tablet LAN.

The cloud never scans restaurant LANs.

1. Worker queues `{ "name": "ping_printer", "args": { "printer": "جريل" } }` on `POST /tickets/{id}/commands` (**202**) or from the live device page.
2. The next `POST /devices/session` returns at most one job in `commands`.
3. The POS runs it locally and sends `commandResults` on the following heartbeat.

Allowlisted names only (never eval, OS shell, or raw SQL):

- `ping_printer` — args: `printer` or `ip`
- `scan_lan` — no args; tablet scans its `/24` for port 9100
- `map_lan` — no args; tablet maps its own `/24` (ping or a short TCP check, plus its ARP table) and returns `lan` nodes and links on the next heartbeat. It does not accept a shell command or a target outside that subnet.
- `list_printers` — configured receipt / sticker / kitchen printers
- `remap_printer` — args: `printer` plus optional `ip` / `port`
- `retry_failed_prints` — reprint queued kitchen fails on that device
- `inspect_order` — args: `order_number`
- `test_print` — args: `printer` or `ip`
- `set_role` — args: `ip`, `role` (`receipt|sticker|kitchen`), `enabled`
- `set_kitchen_type` — args: `ip`, `station` / `stationId`, `selected`
- `remember_printer` / `forget_printer` — args: `ip`
- `sync_kitchen_api` — pull CRM kitchen types on the tablet

`POST /tickets/{id}/commands` returns **409** if no POS device is linked, or if that device already has 5 open jobs. Queued jobs expire after 120 seconds.

CORS: localhost / 127.0.0.1 (any port), Android emulator `10.0.2.2`, LAN `192.168.*`, and `https://kaman-workspace.com`.
