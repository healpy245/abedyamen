<?php

namespace App\Providers;

use App\Models\ChatbotConversation;
use App\Policies\ChatbotConversationPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(ChatbotConversation::class, ChatbotConversationPolicy::class);

        if ($this->app->environment(['local', 'testing'])) {
            Http::globalOptions([
                'verify' => false,
                'curl' => [
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_SSL_VERIFYHOST => 0,
                ],
            ]);
        }
    }
}
