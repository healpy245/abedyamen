<?php

declare(strict_types=1);

namespace App\Services\Malan\Contracts;

interface RequestServiceReactivation
{
    /**
     * @param  array{customer_id: string, conversation_id: int, reason: string, channel: string}  $context
     * @return array<string, mixed>
     */
    public function request(array $context): array;
}
