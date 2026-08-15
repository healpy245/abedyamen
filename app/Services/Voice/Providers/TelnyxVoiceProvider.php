<?php

declare(strict_types=1);

namespace App\Services\Voice\Providers;

use App\Enums\Voice\VoiceProviderName;
use App\Services\Voice\Contracts\VoiceProvider;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TelnyxVoiceProvider implements VoiceProvider
{
    public function name(): string
    {
        return VoiceProviderName::Telnyx->value;
    }

    public function isConfigured(): bool
    {
        return $this->missingConfigurationKeys() === [];
    }

    /**
     * @return list<string>
     */
    public function missingConfigurationKeys(): array
    {
        $missing = [];

        if (blank(config('voice.providers.telnyx.api_key'))) {
            $missing[] = 'TELNYX_API_KEY';
        }

        if (blank(config('voice.providers.telnyx.connection_id'))) {
            $missing[] = 'TELNYX_CONNECTION_ID';
        }

        if (blank(config('voice.providers.telnyx.phone_number'))) {
            $missing[] = 'TELNYX_PHONE_NUMBER';
        }

        if (blank(config('voice.providers.telnyx.public_key'))) {
            $missing[] = 'TELNYX_PUBLIC_KEY';
        }

        return $missing;
    }

    public function answerCall(string $providerCallId, array $context = []): array
    {
        if (! $this->isConfigured()) {
            return $this->notConfiguredResponse('answer');
        }

        return $this->postAction($providerCallId, 'answer', $context);
    }

    public function speakText(string $providerCallId, string $text, array $context = []): array
    {
        if (! $this->isConfigured()) {
            return $this->notConfiguredResponse('speak');
        }

        return $this->postAction($providerCallId, 'speak', array_merge($context, [
            'payload' => [
                'text' => $text,
            ],
        ]));
    }

    public function hangUp(string $providerCallId, array $context = []): array
    {
        if (! $this->isConfigured()) {
            return $this->notConfiguredResponse('hangup');
        }

        return $this->postAction($providerCallId, 'hangup', $context);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function postAction(string $providerCallId, string $action, array $context = []): array
    {
        $apiKey = (string) config('voice.providers.telnyx.api_key');
        $baseUrl = rtrim((string) config('voice.providers.telnyx.api_base_url'), '/');

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->timeout(15)
                ->post("{$baseUrl}/calls/{$providerCallId}/actions/{$action}", $context['payload'] ?? []);

            if (! $response->successful()) {
                Log::warning('Telnyx voice action failed', [
                    'action' => $action,
                    'provider_call_id' => $providerCallId,
                    'status' => $response->status(),
                ]);

                return [
                    'success' => false,
                    'provider' => $this->name(),
                    'action' => $action,
                    'status' => $response->status(),
                ];
            }

            return [
                'success' => true,
                'provider' => $this->name(),
                'action' => $action,
                'provider_call_id' => $providerCallId,
            ];
        } catch (\Throwable $e) {
            Log::warning('Telnyx voice action exception', [
                'action' => $action,
                'provider_call_id' => $providerCallId,
                'error' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'provider' => $this->name(),
                'action' => $action,
                'error' => 'request_failed',
            ];
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function notConfiguredResponse(string $action): array
    {
        return [
            'success' => false,
            'provider' => $this->name(),
            'action' => $action,
            'configured' => false,
            'error' => 'provider_not_configured',
        ];
    }
}
