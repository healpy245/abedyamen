<?php

declare(strict_types=1);

namespace App\Contracts\Voice;

interface TextToSpeechProvider
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function synthesize(string $text, string $voiceName, array $options = []): string;
}
