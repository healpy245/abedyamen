<?php

use App\Http\Controllers\AiChatbot\KamanIgnoredPhoneController;
use App\Http\Controllers\Api\Voice\TelnyxHealthController;
use App\Http\Controllers\Api\Voice\TelnyxWebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('voice/telnyx')->name('voice.telnyx.')->group(function () {
    Route::get('/health', TelnyxHealthController::class)->name('health');
    Route::post('/webhook', [TelnyxWebhookController::class, 'handle'])->name('webhook');
});

Route::post('/kaman-whatsapp/ignored-phones/483275634', [KamanIgnoredPhoneController::class, 'store'])
    ->name('api.kaman-whatsapp.ignored-phones.store');
