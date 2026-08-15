<?php

declare(strict_types=1);

namespace App\Services\Malan\Contracts;

interface ChargeSavedPaymentMethod
{
    /**
     * @param  array{confirmed_by_customer: bool, customer_id: string, amount: float, conversation_id: int, channel: string}  $context
     * @return array<string, mixed>
     */
    public function charge(array $context): array;
}
