<?php

return [

    'api' => [
        'base_url' => rtrim((string) env('MALAN_API_BASE_URL', 'https://www.malan.app'), '/'),
        'key' => env('MALAN_API_KEY'),
        'timeout' => (int) env('MALAN_API_TIMEOUT', 15),
        'retries' => (int) env('MALAN_API_RETRIES', 1),
        'retry_sleep_ms' => (int) env('MALAN_API_RETRY_SLEEP_MS', 200),
    ],

    'bank' => [
        'name' => (string) env('MALAN_BANK_NAME', 'בנק הפועלים'),
        'branch' => (string) env('MALAN_BANK_BRANCH', '665'),
        'account' => (string) env('MALAN_BANK_ACCOUNT', '603495'),
    ],

    'integration_type' => 'malan',

    'verified_context_ttl_hours' => (int) env('MALAN_VERIFIED_CONTEXT_TTL_HOURS', 24),

    'support_report_duplicate_window_minutes' => (int) env('MALAN_SUPPORT_REPORT_DUPLICATE_MINUTES', 30),

    /*
    | Malan createTask assignee user IDs (CRM employee IDs).
    | Accounting (גבייה / ניתוק חוב follow-up) defaults to 147.
    */
    'tasks' => [
        'accounting_user_id' => (int) env('MALAN_TASK_ACCOUNTING_USER_ID', 147),
        'technical_user_id' => (int) env('MALAN_TASK_TECHNICAL_USER_ID', 147),
        'default_status' => (string) env('MALAN_TASK_DEFAULT_STATUS', 'non_urgent'),
    ],

    /*
    | New-signup leads (apiClient/createLead).
    | Set MALAN_LEAD_SOURCE_ID to a valid getLeadSources id, or leave 0 to auto-pick.
    | Campaign WhatsApp leads always use campaign_source_id (default 66).
    */
    'leads' => [
        'default_source_id' => (int) env('MALAN_LEAD_SOURCE_ID', 0),
        'campaign_source_id' => (int) env('MALAN_CAMPAIGN_LEAD_SOURCE_ID', 66),
        'preferred_source_title' => (string) env('MALAN_LEAD_SOURCE_TITLE', ''),
        'source_cache_seconds' => (int) env('MALAN_LEAD_SOURCE_CACHE_SECONDS', 3600),
        'duplicate_window_minutes' => (int) env('MALAN_LEAD_DUPLICATE_MINUTES', 30),
    ],

    'media' => [
        'max_bytes' => (int) env('MALAN_MEDIA_MAX_BYTES', 15 * 1024 * 1024),
        'allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'image/webp',
            'application/pdf',
            'audio/ogg',
            'audio/mpeg',
            'audio/mp4',
            'audio/aac',
            'audio/x-m4a',
            'audio/x-hx-aac-adts',
            'audio/opus',
            'audio/flac',
            'audio/x-flac',
            'audio/wav',
            'audio/webm',
            'audio/x-wav',
            'audio/3gpp',
            'audio/amr',
        ],
        'disk' => (string) env('MALAN_MEDIA_DISK', 'local'),
        'directory' => 'malan/whatsapp-media',
        'download_timeout' => (int) env('MALAN_MEDIA_DOWNLOAD_TIMEOUT', 30),
        'allowed_download_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env(
                'MALAN_MEDIA_ALLOWED_HOSTS',
                'media.green-api.com,api.green-api.com,digitaloceanspaces.com,amazonaws.com'
            ))
        ))),
    ],

    'voice' => [
        'transcription_model' => (string) env('MALAN_VOICE_TRANSCRIPTION_MODEL', 'gpt-transcribe'),
        'transcription_fallback_model' => (string) env('MALAN_VOICE_TRANSCRIPTION_FALLBACK_MODEL', 'whisper-1'),
        // Singular hint for models that take one language; the list is for gpt-transcribe.
        'transcription_language' => (string) env('MALAN_VOICE_TRANSCRIPTION_LANGUAGE', 'ar'),
        'transcription_languages' => (string) env('MALAN_VOICE_TRANSCRIPTION_LANGUAGES', 'ar,he'),
        'transcription_timeout' => (int) env('MALAN_VOICE_TRANSCRIPTION_TIMEOUT', 60),
        'transcription_min_bytes' => (int) env('MALAN_VOICE_TRANSCRIPTION_MIN_BYTES', 900),
        'transcription_min_confidence' => (float) env('MALAN_VOICE_TRANSCRIPTION_MIN_CONFIDENCE', -1.25),
        'transcription_max_no_speech' => (float) env('MALAN_VOICE_TRANSCRIPTION_MAX_NO_SPEECH', 0.75),
        'transcription_logprobs' => (bool) env('MALAN_VOICE_TRANSCRIPTION_LOGPROBS', true),
    ],

    'proof' => [
        'timezone' => 'Asia/Jerusalem',
        'amount_tolerance' => 0.01,
        'min_confidence_verified' => 0.85,
    ],

];
