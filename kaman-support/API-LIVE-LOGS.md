# Live logs API

Base: `https://kaman-workspace.com/api/kaman-support/v1`

Headers (all POS requests):

```
Content-Type: application/json; charset=utf-8
Accept: application/json
X-Kaman-Support-Key: <KAMAN_SUPPORT_API_KEY>
```

Limits: body 256KB · 180 req/min · max 120 logs/request · message 4000 chars · 5000 logs kept per device.

---

## POST `/devices/logs`

Store a log batch. Marks device `open`.

```bash
curl -sS -X POST "https://kaman-workspace.com/api/kaman-support/v1/devices/logs" \
  -H "Content-Type: application/json; charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-Kaman-Support-Key: $KAMAN_SUPPORT_API_KEY" \
  -d '{
    "deviceId": "pos-device-1",
    "restaurant": "kaman",
    "logs": [
      {
        "id": "log-1",
        "level": "info",
        "source": "auth",
        "message": "signed in",
        "at": "2026-09-22T10:00:00.000Z",
        "context": {}
      }
    ]
  }'
```

**Body**

| Field | Required | Type |
|---|---|---|
| `deviceId` | yes | string (or `device_id`) |
| `restaurant` | no | string |
| `restaurantName` | no | string |
| `logs` | yes | array 1–120 |
| `logs[].message` | yes | string |
| `logs[].id` | no | string, idempotent |
| `logs[].level` | no | `info` `warn` `error` `debug` |
| `logs[].source` | no | string, default `app` |
| `logs[].context` | no | object |
| `logs[].at` | no | ISO-8601 |

**200**

```json
{
  "ok": true,
  "id": 12,
  "deviceId": "pos-device-1",
  "accepted": 1,
  "logCount": 1,
  "lastSeenAt": "2026-09-22T10:49:00+00:00"
}
```

**Errors:** 401 unauthorized · 413 body too large · 422 validation · 429 rate limit

---

## POST `/devices/session`

Heartbeat. Optional `logs` (same shape as above).

```bash
curl -sS -X POST "https://kaman-workspace.com/api/kaman-support/v1/devices/session" \
  -H "Content-Type: application/json; charset=utf-8" \
  -H "Accept: application/json" \
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
    "logs": [
      {"id":"log-1","level":"info","source":"auth","message":"signed in"}
    ]
  }'
```

**Body (optional except deviceId):**  
`deviceId` `restaurant` `restaurantName` `cashierName` `cashierEmail` `role` `platform` `deviceKind` `appVersion` `hostname` `locale` `sessionId` `appState` (`open`\|`background`\|`closed`) `loggedIn` `logs` `commandResults` `printers` `discovered` `kitchenTypes` `scan`

**200**

```json
{
  "ok": true,
  "id": 12,
  "deviceId": "pos-device-1",
  "restaurant": "kaman",
  "appState": "open",
  "online": true,
  "watch": false,
  "pollMs": 15000,
  "logCount": 1,
  "lastSeenAt": "2026-09-22T10:49:00+00:00",
  "commands": []
}
```

---

## GET `/devices/session`

```bash
curl -sS "https://kaman-workspace.com/api/kaman-support/v1/devices/session" \
  -H "Accept: application/json" \
  -H "X-Kaman-Support-Key: $KAMAN_SUPPORT_API_KEY"
```

**200**

```json
{
  "ok": true,
  "service": "kaman-support-live",
  "heartbeatSeconds": 15,
  "liveWindowSeconds": 90,
  "path": "/api/kaman-support/v1/devices/session",
  "logsPath": "/api/kaman-support/v1/devices/logs"
}
```

---

## POST `/devices/close`

```bash
curl -sS -X POST "https://kaman-workspace.com/api/kaman-support/v1/devices/close" \
  -H "Content-Type: application/json; charset=utf-8" \
  -H "Accept: application/json" \
  -H "X-Kaman-Support-Key: $KAMAN_SUPPORT_API_KEY" \
  -d '{"deviceId":"pos-device-1","restaurant":"kaman"}'
```

**200** `{ "ok": true, "id": 12, "deviceId": "pos-device-1", "appState": "closed", "online": false }`
