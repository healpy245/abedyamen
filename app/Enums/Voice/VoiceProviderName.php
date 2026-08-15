<?php

declare(strict_types=1);

namespace App\Enums\Voice;

enum VoiceProviderName: string
{
    case Fake = 'fake';
    case Telnyx = 'telnyx';
}
