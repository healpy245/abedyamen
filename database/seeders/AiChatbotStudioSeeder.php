<?php

namespace Database\Seeders;

use App\Services\AiChatbot\AiChatbotSettingsService;
use Illuminate\Database\Seeder;

class AiChatbotStudioSeeder extends Seeder
{
    public function run(): void
    {
        /** @var AiChatbotSettingsService $service */
        $service = app(AiChatbotSettingsService::class);
        $service->ensureDefaults();
    }
}

