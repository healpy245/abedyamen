<?php

declare(strict_types=1);

namespace App\Services\Malan\Payment;

use App\Services\Malan\Contracts\CreateOneTimePaymentLink;

class PendingCreateOneTimePaymentLink implements CreateOneTimePaymentLink
{
    public function create(array $context): array
    {
        return [
            'success' => false,
            'integration_pending' => true,
            'message' => 'رابط الدفع لمرة واحدة غير متاح حاليًا. رح نحوّل الموضوع للجباية تتابع معك.',
        ];
    }
}
