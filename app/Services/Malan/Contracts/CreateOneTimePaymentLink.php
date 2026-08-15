<?php

declare(strict_types=1);

namespace App\Services\Malan\Contracts;

interface CreateOneTimePaymentLink
{
    /**
     * @param  array{confirmed_by_customer: bool, customer_id: string, amount: float, conversation_id: int, delivery_channel: string}  $context
     * @return array<string, mixed>
     */
    public function create(array $context): array;
}
