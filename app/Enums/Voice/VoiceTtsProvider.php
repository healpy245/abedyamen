<?php

declare(strict_types=1);

namespace App\Enums\Voice;

enum VoiceTtsProvider: string
{
    case Auto = 'auto';
    case Edge = 'edge';
    case OpenAi = 'openai';
    case ElevenLabs = 'elevenlabs';
    case Telnyx = 'telnyx';
    case Browser = 'browser';
}
