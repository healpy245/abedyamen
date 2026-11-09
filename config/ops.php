<?php

declare(strict_types=1);

return [
    // Sent as the X-Ops-Key header on /ops/* calls. Nothing under /ops/ opens to a guest while this is empty.
    'key' => env('OPS_KEY', ''),

    // The Sally / Malan instance's full Green API send URL. It holds the instance token, so it stays in .env.
    'malan_greenapi_url' => env('MALAN_GREENAPI_URL', ''),
];
