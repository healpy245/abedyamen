<?php

declare(strict_types=1);

namespace App\Enums\Voice;

enum VoiceCallMessageRole: string
{
    case Caller = 'caller';
    case Assistant = 'assistant';
    case System = 'system';
}
