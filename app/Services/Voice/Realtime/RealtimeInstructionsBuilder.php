<?php

declare(strict_types=1);

namespace App\Services\Voice\Realtime;

use App\Models\AiChatbot\ChatbotInstance;

class RealtimeInstructionsBuilder
{
    public function build(ChatbotInstance $instance, string $locale = 'ar'): string
    {
        $tenantPrompt = trim((string) ($instance->system_prompt ?? ''));
        $voiceLayer = trim((string) __('voice.realtime.agent_instructions', [], $this->languageKey($locale)));
        $toolLayer = trim((string) __('voice.realtime.tool_instructions', [], $this->languageKey($locale)));

        $sections = [
            "[VOICE DELIVERY — HIGHEST PRIORITY]\n{$voiceLayer}",
        ];

        if ($tenantPrompt !== '') {
            $sections[] = "[BUSINESS KNOWLEDGE AND RULES]\n"
                .'Use the following tenant knowledge to decide what to say. '
                ."Do not repeat the opening greeting from this section.\n"
                ."{$tenantPrompt}";
        }

        if ($toolLayer !== '') {
            $sections[] = "[TOOL AND TRUTHFULNESS RULES]\n{$toolLayer}";
        }

        return implode("\n\n", $sections);
    }

    public function openingGreeting(string $locale = 'ar'): string
    {
        $greeting = (string) config('voice.realtime.opening_greeting.'.$this->languageKey($locale))
            ?: (string) config('voice.realtime.opening_greeting.ar');

        return trim($greeting);
    }

    public function openingGreetingInstructions(string $locale = 'ar'): string
    {
        $greeting = $this->openingGreeting($locale);

        return str_replace(':greeting', $greeting, (string) __('voice.realtime.opening_greeting_instructions', [], $this->languageKey($locale)));
    }

    private function languageKey(string $locale): string
    {
        $primary = strtolower(explode('-', trim($locale))[0] ?? 'ar');

        return match ($primary) {
            'he' => 'he',
            'en' => 'en',
            default => 'ar',
        };
    }
}
