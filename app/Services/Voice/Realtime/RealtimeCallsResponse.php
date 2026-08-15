<?php

declare(strict_types=1);

namespace App\Services\Voice\Realtime;

class RealtimeCallsResponse
{
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        public readonly string $contentType,
        public readonly string $transport = 'guzzle',
    ) {}

    public function successful(): bool
    {
        return $this->status >= 200 && $this->status < 300;
    }
}
