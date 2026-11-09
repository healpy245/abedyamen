<?php

return [
    // Israeli VAT, in percent. Applied to every document type except a plain receipt.
    'vat_rate' => (float) env('FINANCE_VAT_RATE', 18),

    // A linked client opens at this URL followed by its MFITGROUP client id.
    'mfit_client_url' => env('FINANCE_MFIT_CLIENT_URL', 'https://mfit.karmelfiber.com/admin/clients/view'),

    // Suggestions for the billed-features list; any other name can be typed.
    'billing_features' => ['POS', 'Online ordering', 'Kiosk', 'QR menu', 'Kitchen display', 'Delivery app', 'Hardware rental'],
];
