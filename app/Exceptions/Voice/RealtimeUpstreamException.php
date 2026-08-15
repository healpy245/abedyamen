<?php

declare(strict_types=1);

namespace App\Exceptions\Voice;

use RuntimeException;

class RealtimeUpstreamException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly int $upstreamStatus,
        public readonly string $upstreamBody,
    ) {
        parent::__construct($message);
    }

    /**
     * @return array<string, mixed>
     */
    public function debugContext(): array
    {
        $decoded = json_decode($this->upstreamBody, true);
        $upstreamError = is_array($decoded)
            ? ($decoded['error']['message'] ?? $decoded['message'] ?? null)
            : null;

        return array_filter([
            'upstream_status' => $this->upstreamStatus,
            'upstream_error' => is_string($upstreamError) ? $upstreamError : null,
            'upstream_body' => config('app.debug') ? $this->redactSecrets($this->upstreamBody) : null,
        ], fn ($value) => $value !== null && $value !== '');
    }

    private function redactSecrets(string $body): string
    {
        return (string) preg_replace('/\bsk-[A-Za-z0-9_-]{10,}\b/', '[redacted]', $body);
    }
}
