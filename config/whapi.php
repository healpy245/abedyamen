<?php

declare(strict_types=1);

return [
    /*
    | Leave WHATSAPP_PROVIDER empty to use the switch saved in workspace settings.
    | Set it to "whapi" or "greenapi" only when this environment should force one.
    */
    'provider' => env('WHATSAPP_PROVIDER'),

    'token' => env('WHAPI_TOKEN'),

    'channel_id' => env('WHAPI_CHANNEL_ID', 'HULKBR-69QST'),

    'base_url' => rtrim((string) env('WHAPI_BASE_URL', 'https://gate.whapi.cloud'), '/'),

    'webhook_token' => env('WHAPI_WEBHOOK_TOKEN'),

    'default_instance_id' => (int) env('WHAPI_DEFAULT_INSTANCE_ID', 0),
];
