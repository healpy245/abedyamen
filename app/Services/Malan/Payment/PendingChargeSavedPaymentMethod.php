<?php

declare(strict_types=1);

namespace App\Services\Malan\Payment;

use App\Services\Malan\Contracts\ChargeSavedPaymentMethod;

class PendingChargeSavedPaymentMethod implements ChargeSavedPaymentMethod
{
    public function charge(array $context): array
    {
        return [
            'success' => false,
            'integration_pending' => true,
            'message' => 'الدفع الإلكتروني بالبطاقة المسجلة ما زال يحتاج متابعة من الجباية.',
        ];
    }
}
