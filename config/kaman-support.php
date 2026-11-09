<?php

declare(strict_types=1);

return [

    /*
    | Shared secret the Kaman POS sends as X-Kaman-Support-Key.
    | Keep this in .env / .kaman-support.env — never commit a real value.
    */
    'api_key' => (string) env('KAMAN_SUPPORT_API_KEY', ''),

    'max_body_bytes' => (int) env('KAMAN_SUPPORT_MAX_BODY_BYTES', 262144),

    'max_media_bytes' => (int) env('KAMAN_SUPPORT_MAX_MEDIA_BYTES', 50331648),

    'ticket_rate_per_minute' => (int) env('KAMAN_SUPPORT_TICKET_RATE_PER_MINUTE', 30),

    'live_rate_per_minute' => (int) env('KAMAN_SUPPORT_LIVE_RATE_PER_MINUTE', 180),

    'live_seen_seconds' => (int) env('KAMAN_SUPPORT_LIVE_SEEN_SECONDS', 90),

    'max_logs_per_device' => (int) env('KAMAN_SUPPORT_MAX_LOGS_PER_DEVICE', 5000),

    // Failures stay long enough to diagnose a device. Routine success lines do not.
    'log_failure_days' => (int) env('KAMAN_SUPPORT_LOG_FAILURE_DAYS', 14),

    'log_routine_days' => (int) env('KAMAN_SUPPORT_LOG_ROUTINE_DAYS', 1),

    'apk_path' => storage_path('app/private/kaman-support.apk'),

    'max_log_batch' => (int) env('KAMAN_SUPPORT_MAX_LOG_BATCH', 120),

    'log_message_max' => (int) env('KAMAN_SUPPORT_LOG_MESSAGE_MAX', 4000),

    'display_timezone' => (string) env('KAMAN_SUPPORT_DISPLAY_TIMEZONE', 'Asia/Jerusalem'),

    'cors_origin_patterns' => [
        '#^https://kaman-workspace\.com$#',
        '#^https://www\.kaman-workspace\.com$#',
        '#^https?://localhost(:\d+)?$#',
        '#^https?://127\.0\.0\.1(:\d+)?$#',
        '#^https?://\[::1\](:\d+)?$#',
        '#^http://10\.0\.2\.2(:\d+)?$#', // Android emulator host
        '#^http://192\.168\.\d{1,3}\.\d{1,3}(:\d+)?$#',
    ],

    'command_ttl_seconds' => (int) env('KAMAN_SUPPORT_COMMAND_TTL_SECONDS', 120),

    'allowlisted_commands' => [
        'ping_printer',
        'scan_lan',
        'map_lan',
        'list_printers',
        'remap_printer',
        'retry_failed_prints',
        'inspect_order',
        'test_print',
        'set_role',
        'set_kitchen_type',
        'remember_printer',
        'forget_printer',
        'sync_kitchen_api',
    ],

];
