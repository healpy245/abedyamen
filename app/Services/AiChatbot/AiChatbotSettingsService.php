<?php

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotSetting;

class AiChatbotSettingsService
{
    /** Calibration sample: this Arabic sentence should take ~15 seconds to "type". */
    public const TYPING_REFERENCE_SAMPLE = 'إن شاء الله! مجموعة "المشتركة" عندهم رؤية واضحة لمستقبل أفضل. إذا احتجت أي معلومات إضافية عنهم، أنا جاهز!';

    public function defaults(): array
    {
        return [
            'system_prompt' => implode("\n", [
                'You are a helpful AI assistant.',
                'Answer clearly and accurately.',
                'Keep responses concise.',
                'If the user writes Arabic, reply in Arabic.',
                'If the user writes English, reply in English.',
            ]),
            'chatbot_model' => 'gpt-4o-mini',
            'temperature' => 0.7,
            'max_tokens' => 2000,
            'typing_delay_enabled' => true,
            'typing_reference_chars' => mb_strlen(self::TYPING_REFERENCE_SAMPLE),
            'typing_reference_seconds' => 15,
            'typing_min_seconds' => 2,
            'typing_max_seconds' => 45,
        ];
    }

    /**
     * Dynamic typing delay before showing an assistant message (milliseconds).
     */
    public function typingDelayMs(string $text): int
    {
        $settings = $this->all();

        if (!($settings['typing_delay_enabled'] ?? true)) {
            return 0;
        }

        $referenceChars = max(1, (int) ($settings['typing_reference_chars'] ?? mb_strlen(self::TYPING_REFERENCE_SAMPLE)));
        $referenceSeconds = max(1, (int) ($settings['typing_reference_seconds'] ?? 15));
        $minSeconds = max(0, (int) ($settings['typing_min_seconds'] ?? 2));
        $maxSeconds = max($minSeconds, (int) ($settings['typing_max_seconds'] ?? 45));

        $length = mb_strlen(trim($text));
        $seconds = ($length / $referenceChars) * $referenceSeconds;
        $seconds = max($minSeconds, min($maxSeconds, $seconds));

        return (int) round($seconds * 1000);
    }

    public function ensureDefaults(): void
    {
        $defaults = $this->defaults();

        foreach ($defaults as $key => $value) {
            ChatbotSetting::firstOrCreate(
                ['key' => $key],
                ['value' => is_scalar($value) ? (string) $value : json_encode($value)]
            );
        }
    }

    public function all(): array
    {
        $defaults = $this->defaults();

        $overrides = ChatbotSetting::query()
            ->pluck('value', 'key')
            ->toArray();

        $merged = $defaults;

        foreach ($overrides as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                continue;
            }

            $merged[$key] = $this->castValue($key, $value, $defaults[$key]);
        }

        return $merged;
    }

    public function save(array $data): void
    {
        $defaults = $this->defaults();

        foreach ($data as $key => $value) {
            if (!array_key_exists($key, $defaults)) {
                continue;
            }

            ChatbotSetting::updateOrCreate(
                ['key' => $key],
                ['value' => is_scalar($value) ? (string) $value : json_encode($value)]
            );
        }
    }

    public function resetToDefaults(): void
    {
        $defaults = $this->defaults();

        foreach ($defaults as $key => $value) {
            ChatbotSetting::updateOrCreate(
                ['key' => $key],
                ['value' => is_scalar($value) ? (string) $value : json_encode($value)]
            );
        }
    }

    protected function castValue(string $key, mixed $value, mixed $default): mixed
    {
        if ($value === null) {
            return $default;
        }

        if (is_string($default)) {
            return (string) $value;
        }

        if (is_int($default)) {
            return (int) $value;
        }

        if (is_float($default)) {
            return (float) $value;
        }

        if (is_bool($default)) {
            return filter_var($value, FILTER_VALIDATE_BOOLEAN);
        }

        return $default;
    }
}

