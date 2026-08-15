<?php

declare(strict_types=1);

namespace App\Enums\Voice;

enum VoiceProfile: string
{
    case Woman = 'woman';
    case Man = 'man';
    case Girl = 'girl';
    case Boy = 'boy';

    public function label(): string
    {
        return __('voice.profiles.'.$this->value);
    }

    /**
     * @return array{pitch: float, rate: float, prefer: list<string>}
     */
    public function synthesis(): array
    {
        $config = config('voice.profiles.'.$this->value, []);

        return [
            'pitch' => (float) ($config['pitch'] ?? 1.0),
            'rate' => (float) ($config['rate'] ?? 1.0),
            'prefer' => is_array($config['prefer'] ?? null) ? $config['prefer'] : [],
        ];
    }
}
