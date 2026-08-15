<?php

declare(strict_types=1);

namespace App\Services\Voice\Providers;

use App\Enums\Voice\VoiceProviderName;
use App\Services\Voice\Contracts\VoiceProvider;
use Illuminate\Support\Str;

class FakeVoiceProvider implements VoiceProvider
{
    public function name(): string
    {
        return VoiceProviderName::Fake->value;
    }

    public function isConfigured(): bool
    {
        return true;
    }

    public function answerCall(string $providerCallId, array $context = []): array
    {
        return [
            'success' => true,
            'provider' => $this->name(),
            'provider_call_id' => $providerCallId,
            'action' => 'answered',
            'simulated' => true,
        ];
    }

    public function speakText(string $providerCallId, string $text, array $context = []): array
    {
        return [
            'success' => true,
            'provider' => $this->name(),
            'provider_call_id' => $providerCallId,
            'action' => 'speak',
            'text_length' => mb_strlen($text),
            'simulated' => true,
        ];
    }

    public function hangUp(string $providerCallId, array $context = []): array
    {
        return [
            'success' => true,
            'provider' => $this->name(),
            'provider_call_id' => $providerCallId,
            'action' => 'hangup',
            'simulated' => true,
        ];
    }

    public static function generateCallId(): string
    {
        return 'fake_'.Str::uuid()->toString();
    }
}
