<?php

return [

    /*
    | v1 keeps the current DimaPrompt engine. v2 answers the latest message
    | with one model call and does not replace that reply with a script.
    | v3 is the separate engine. The default stays v1.
    */
    'engine' => env('DIMA_ENGINE', 'v1'),

    /*
    | Live WhatsApp sending for Dima. Off by default; the test chat works either way.
    */
    'outbound' => (bool) env('DIMA_OUTBOUND', false),

    /*
    | Days after last_verified_at before a figure is treated as stale.
    | Stale figures stay in the file, and the prompt is told not to
    | present them as the current situation.
    */
    'facts_max_age_days' => (int) env('DIMA_FACTS_MAX_AGE_DAYS', 540),

];
