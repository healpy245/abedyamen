# Voice Bot Setup

This document describes the provider-independent voice-call system added to the AI Chatbot Studio.

## What was added

- Database tables: `voice_calls`, `voice_call_messages`
- Models: `App\Models\Voice\VoiceCall`, `App\Models\Voice\VoiceCallMessage`
- Provider abstraction under `app/Services/Voice/`
- Fake provider for local simulation (no external services)
- Telnyx provider skeleton (disabled until credentials are configured)
- Authenticated voice simulator UI at `/ai-chatbot/instances/{instance}/voice`
- Public Telnyx-ready API endpoints under `/api/voice/telnyx/`

## Database structure

### `voice_calls`

| Column | Description |
|--------|-------------|
| `user_id` | Owner of the call |
| `chatbot_instance_id` | Chatbot instance used for AI replies |
| `provider` | `fake` or `telnyx` |
| `provider_call_id` | External call identifier (indexed) |
| `caller_number` / `called_number` | Optional phone numbers |
| `status` | `pending`, `ringing`, `active`, `completed`, `failed`, `cancelled` |
| `chatbot_conversation_id` | Linked AI conversation (nullable) |
| `started_at`, `answered_at`, `ended_at`, `duration_seconds` | Call lifecycle |
| `failure_reason`, `metadata` | Diagnostics and provider metadata |

### `voice_call_messages`

| Column | Description |
|--------|-------------|
| `voice_call_id` | Parent call |
| `role` | `caller`, `assistant`, or `system` |
| `content` | Transcript text |
| `provider_event_id` | Unique provider event id (deduplication) |
| `metadata` | Optional JSON metadata |

## Environment variables

Add to `.env`:

```env
VOICE_PROVIDER=fake
VOICE_DEFAULT_CHATBOT_INSTANCE_ID=

TELNYX_API_KEY=
TELNYX_PUBLIC_KEY=
TELNYX_CONNECTION_ID=
TELNYX_PHONE_NUMBER=
TELNYX_WEBHOOK_VERIFY=true
TELNYX_WEBHOOK_VERIFY_BYPASS=false
```

Never commit real Telnyx secrets. The health endpoint reports missing keys without exposing values.

## Migrations and tests

```bash
composer dump-autoload
php artisan migrate
php artisan test --filter=Voice
```

## Fake provider testing (simulator)

1. Sign in as a user with the `ai-chatbot` project.
2. Open AI Chatbot Studio and select an instance.
3. Click **Voice simulator** in the sidebar (or visit `/ai-chatbot/instances/{id}/voice`).
4. Optionally enter a caller number and click **Start simulated call**.
5. Type caller speech, e.g. `مرحبا، ما هي خدمات الشركة؟`, and send.
6. The assistant reply appears in the transcript and is stored in `voice_call_messages`.
7. The same text is also sent through `AiChatbotService` and stored in the chatbot conversation tables.
8. Click **End call**. Further messages are rejected.

## Switching from fake to Telnyx

1. Purchase/configure a Telnyx number and Voice API application.
2. Set:

```env
VOICE_PROVIDER=telnyx
VOICE_DEFAULT_CHATBOT_INSTANCE_ID=<your-instance-id>
TELNYX_API_KEY=...
TELNYX_PUBLIC_KEY=...
TELNYX_CONNECTION_ID=...
TELNYX_PHONE_NUMBER=...
TELNYX_WEBHOOK_VERIFY=true
```

3. Point Telnyx webhooks to:

```
POST https://your-domain.com/api/voice/telnyx/webhook
```

4. Verify readiness:

```
GET https://your-domain.com/api/voice/telnyx/health
```

## Browser voice call TTS (Arabic / Hebrew / English)

The voice simulator phone mode uses **server-side neural TTS** instead of the browser’s built-in speech engine (which often has no Arabic voices on Windows).

| `VOICE_TTS_PROVIDER` | Description |
|----------------------|-------------|
| `elevenlabs` | ElevenLabs Multilingual voices (recommended for natural Arabic) |
| `auto` (default) | ElevenLabs when `ELEVENLABS_API_KEY` is set, then OpenAI, then Edge |
| `openai` | OpenAI `gpt-4o-mini-tts` / `tts-1-hd` |
| `edge` | Microsoft Edge neural voices (legacy; may be unavailable) |
| `browser` | Fallback to Web Speech API in the client |

### ElevenLabs (Arabic neural TTS)

```env
VOICE_TTS_PROVIDER=auto
ELEVENLABS_API_KEY=
ELEVENLABS_MODEL=eleven_multilingual_v2
ELEVENLABS_VOICE_AR_WOMAN=pFZP5JQG7iQjIQuC4Bku
ELEVENLABS_STABILITY=0.68
ELEVENLABS_SIMILARITY=0.85
ELEVENLABS_STYLE=0.0
ELEVENLABS_SPEED=0.92
ELEVENLABS_OPTIMIZE_LATENCY=0
VOICE_TTS_ARABIC_DIACRITIZE=true
VOICE_PHONE_MAX_TOKENS=90
```

- Arabic replies use **Telnyx Bayan** first when `TELNYX_API_KEY` is set (e.g. `Telnyx.Bayan.Yara`), then OpenAI, Edge `ar-JO-SanaNeural`, then ElevenLabs.
- Set in `.env`:
  ```env
  TELNYX_API_KEY=...
  TELNYX_TTS_VOICE_AR_WOMAN=Telnyx.Bayan.Yara
  VOICE_TTS_PROVIDER=auto
  ```
- Set `VOICE_TTS_ARABIC_DIACRITIZE=false` (default) for lower latency and dialect speech.
- For a true ElevenLabs Palestinian library voice, upgrade the plan and set `ELEVENLABS_VOICE_AR_WOMAN=<voice_id>` with `VOICE_TTS_PROVIDER=elevenlabs`.

Voice profiles map to neural voices in `config/voice.php` under `tts.elevenlabs.voices`, `tts.edge.voices`, and `tts.openai.voices`.

## What remains for real-time voice/audio

- Telnyx webhook signature verification (public key present but verification not fully implemented)
- Speech-to-text ingestion from Telnyx media streams
- Text-to-speech playback over live call audio
- Real-time streaming architecture (WebSocket/media fork)
- Production call routing rules and per-tenant phone numbers

## Security notes

- Webhook requests are rejected in non-local environments when `TELNYX_WEBHOOK_VERIFY=true` and `TELNYX_PUBLIC_KEY` is missing.
- A local/testing bypass is available only via explicit `TELNYX_WEBHOOK_VERIFY_BYPASS=true`.
- Telnyx API keys and public keys are never returned by API responses or logged in full payloads.
- Users may only access their own chatbot instances and voice calls.
