<?php

namespace App\Providers;

use App\Services\Voice\Contracts\VoiceProvider;
use App\Services\Voice\VoiceManager;
use Illuminate\Support\ServiceProvider;

class VoiceServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(VoiceManager::class);

        $this->app->bind(VoiceProvider::class, function ($app): VoiceProvider {
            return $app->make(VoiceManager::class)->current();
        });
    }

    public function boot(): void
    {
        //
    }
}
