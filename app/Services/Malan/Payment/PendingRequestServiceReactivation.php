<?php

declare(strict_types=1);

namespace App\Services\Malan\Payment;

use App\Services\Malan\Contracts\RequestServiceReactivation;

class PendingRequestServiceReactivation implements RequestServiceReactivation
{
    public function request(array $context): array
    {
        return [
            'success' => false,
            'integration_pending' => true,
            'message' => 'طلب إعادة الخدمة سُجّل داخليًا للمتابعة، لكن إعادة التفعيل التلقائية غير متاحة بعد.',
            'escalated' => true,
        ];
    }
}
