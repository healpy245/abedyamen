<?php

declare(strict_types=1);

namespace App\Services\Voice;

use App\Enums\Voice\VoiceProviderName;
use App\Services\Voice\Contracts\VoiceProvider;
use App\Services\Voice\Providers\FakeVoiceProvider;
use App\Services\Voice\Providers\TelnyxVoiceProvider;
use InvalidArgumentException;

class VoiceManager
{
    public function __construct(
        protected FakeVoiceProvider $fakeProvider,
        protected TelnyxVoiceProvider $telnyxProvider,
    ) {}

    public function provider(?string $name = null): VoiceProvider
    {
        $name = $name ?? (string) config('voice.provider', VoiceProviderName::Fake->value);

        return match ($name) {
            VoiceProviderName::Fake->value => $this->fakeProvider,
            VoiceProviderName::Telnyx->value => $this->telnyxProvider,
            default => throw new InvalidArgumentException("Unsupported voice provider [{$name}]."),
        };
    }

    public function current(): VoiceProvider
    {
        return $this->provider();
    }
}
