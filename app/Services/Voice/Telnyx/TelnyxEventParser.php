<?php

declare(strict_types=1);

namespace App\Services\Voice\Telnyx;

class TelnyxEventParser
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{
     *     event_id: string|null,
     *     event_type: string|null,
     *     provider_call_id: string|null,
     *     caller_number: string|null,
     *     called_number: string|null,
     *     occurred_at: string|null
     * }
     */
    public function parse(array $payload): array
    {
        $data = is_array($payload['data'] ?? null) ? $payload['data'] : [];
        $payloadBody = is_array($data['payload'] ?? null) ? $data['payload'] : [];

        return [
            'event_id' => isset($data['id']) ? (string) $data['id'] : null,
            'event_type' => isset($data['event_type']) ? (string) $data['event_type'] : null,
            'provider_call_id' => $this->resolveCallId($payloadBody),
            'caller_number' => isset($payloadBody['from']) ? (string) $payloadBody['from'] : null,
            'called_number' => isset($payloadBody['to']) ? (string) $payloadBody['to'] : null,
            'occurred_at' => isset($data['occurred_at']) ? (string) $data['occurred_at'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payloadBody
     */
    protected function resolveCallId(array $payloadBody): ?string
    {
        if (isset($payloadBody['call_control_id'])) {
            return (string) $payloadBody['call_control_id'];
        }

        if (isset($payloadBody['call_session_id'])) {
            return (string) $payloadBody['call_session_id'];
        }

        return null;
    }
}
