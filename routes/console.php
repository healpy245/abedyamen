<?php

use App\Services\AiChatbot\MansourBotService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::call(function (): void {
    app(MansourBotService::class)->sendDueInviteNudges();
})->everyMinute()->name('invite-nudges')->withoutOverlapping();
