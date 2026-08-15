<?php

declare(strict_types=1);

namespace App\Services\Voice\Contracts;

interface VoiceProvider
{
    public function name(): string;

    public function isConfigured(): bool;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function answerCall(string $providerCallId, array $context = []): array;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function speakText(string $providerCallId, string $text, array $context = []): array;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function hangUp(string $providerCallId, array $context = []): array;
}
