<?php

declare(strict_types=1);

namespace App\Enums\Voice;

enum VoiceInteractionMode: string
{
    case Text = 'text';
    case Phone = 'phone';

    public function label(): string
    {
        return __('voice.modes.'.$this->value);
    }
}
