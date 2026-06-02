<?php

namespace App\Services\AiChatbot;

use App\Models\AiChatbot\ChatbotSetting;

class AiChatbotSettingsService
{
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
        ];
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

