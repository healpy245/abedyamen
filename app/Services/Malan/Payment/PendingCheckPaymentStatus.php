<?php

declare(strict_types=1);

namespace App\Services\Malan\Payment;

use App\Services\Malan\Contracts\CheckPaymentStatus;

class PendingCheckPaymentStatus implements CheckPaymentStatus
{
    public function check(string $paymentAttemptId): array
    {
        return [
            'success' => false,
            'integration_pending' => true,
            'payment_attempt_id' => $paymentAttemptId,
            'message' => 'فحص حالة الدفع الإلكتروني غير متاح حاليًا.',
        ];
    }
}
