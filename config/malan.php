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
            'audio/opus',
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

    'proof' => [
        'timezone' => 'Asia/Jerusalem',
        'amount_tolerance' => 0.01,
        'min_confidence_verified' => 0.85,
    ],

];
