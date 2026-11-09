<?php

return [
    // Server-side only. The secret must never reach the browser.
    'app_key' => env('TRANZILA_APP_KEY'),
    'secret' => env('TRANZILA_SECRET'),

    // Issues accounting documents. Only mfitltd has its document settings completed.
    'terminal' => env('TRANZILA_TERMINAL', 'mfitltd'),

    // Runs cards and tokens. Documents for these charges are still issued on the terminal above.
    'charge_terminal' => env('TRANZILA_CHARGE_TERMINAL') ?: 'mfitltdtok',

    'urls' => [
        'payments' => rtrim((string) env('TRANZILA_PAYMENTS_URL', 'https://api.tranzila.com'), '/'),
        'documents' => rtrim((string) env('TRANZILA_DOCUMENTS_URL', 'https://billing5.tranzila.com'), '/'),
        // The docs serve the end-customer document page from a different host than the document API.
        'document_view' => rtrim((string) env('TRANZILA_DOCUMENT_VIEW_URL', 'https://my.tranzila.com'), '/'),
        'reports' => rtrim((string) env('TRANZILA_REPORTS_URL', 'https://report.tranzila.com'), '/'),
    ],

    'hosted_fields' => [
        'script' => 'https://hf.tranzila.com/assets/js/thostedf.js',
        'sandbox' => filter_var(env('TRANZILA_HOSTED_FIELDS_SANDBOX', 'false'), FILTER_VALIDATE_BOOLEAN),
        // Hosted Fields rejects a zero amount, so a card is validated (J2, no funds taken) against this sum.
        'capture_amount' => (float) env('TRANZILA_CAPTURE_AMOUNT', 1),
    ],

    'documents' => [
        'default_type' => env('TRANZILA_DOCUMENT_TYPE', 'IR'),
        'language' => env('TRANZILA_DOCUMENT_LANGUAGE', 'heb'),
    ],

    // Turn on only after field 20 is defined as DCdisable under Additional Fields for Transaction in
    // my.tranzila. Tranzila then refuses a repeat of a successful charge for up to 24 hours.
    'dc_disable' => filter_var(env('TRANZILA_DC_DISABLE', 'false'), FILTER_VALIDATE_BOOLEAN),

    'system_name' => env('TRANZILA_SYSTEM_NAME', 'abedyamen-crm'),
    'ssl_verify' => filter_var(env('TRANZILA_SSL_VERIFY', 'true'), FILTER_VALIDATE_BOOLEAN),
    'connect_timeout' => (int) env('TRANZILA_CONNECT_TIMEOUT', 10),
    'timeout' => (int) env('TRANZILA_TIMEOUT', 60),
];
