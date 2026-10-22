<?php

use App\Http\Controllers\AiChatbot\KamanIgnoredPhoneController;
use App\Http\Controllers\Api\KamanApp\ApkDownloadController;
use App\Http\Controllers\Api\KamanApp\SharedCartController;
use App\Http\Controllers\Api\KamanSupport\DeviceSessionController;
use App\Http\Controllers\Api\KamanSupport\HealthController as KamanSupportHealthController;
use App\Http\Controllers\Api\KamanSupport\TicketController as KamanSupportTicketController;
use App\Http\Controllers\Api\Voice\TelnyxHealthController;
use App\Http\Controllers\Api\Voice\TelnyxWebhookController;
use App\Http\Middleware\KamanSupport\AuthenticateSupportKey;
use App\Http\Middleware\KamanSupport\HandleSupportCors;
use App\Http\Middleware\KamanSupport\LimitSupportPayload;
use Illuminate\Support\Facades\Route;

Route::prefix('voice/telnyx')->name('voice.telnyx.')->group(function () {
    Route::get('/health', TelnyxHealthController::class)->name('health');
    Route::post('/webhook', [TelnyxWebhookController::class, 'handle'])->name('webhook');
});

Route::post('/kaman-whatsapp/ignored-phones/483275634', [KamanIgnoredPhoneController::class, 'store'])
    ->name('api.kaman-whatsapp.ignored-phones.store');

Route::get('/kaman-app/v1/version', function () {
    return response()->json([
        'version_name' => config('kaman-app.version_name'),
        'version_code' => (int) config('kaman-app.version_code'),
        'label' => config('kaman-app.version_label'),
    ]);
})->middleware('throttle:30,1')->name('kaman-app.version');

Route::get('/kaman-app/v1/apk', ApkDownloadController::class)
    ->middleware('throttle:20,1')
    ->name('kaman-app.apk');

Route::prefix('shared-carts')
    ->middleware('throttle:600,1')
    ->name('shared-carts.')
    ->group(function (): void {
        Route::post('/', [SharedCartController::class, 'store'])->name('store');
        Route::get('/{code}', [SharedCartController::class, 'show'])->name('show');
        Route::put('/{code}', [SharedCartController::class, 'update'])->name('update');
    });

Route::prefix('kaman-support/v1')
    ->name('kaman-support.pos.')
    ->middleware([HandleSupportCors::class])
    ->group(function (): void {
        Route::get('/health', KamanSupportHealthController::class)->name('health');
        Route::options('/{any}', KamanSupportHealthController::class)->where('any', '.*');

        Route::middleware([LimitSupportPayload::class, AuthenticateSupportKey::class, 'throttle:kaman-support-tickets'])
            ->post('/tickets', [KamanSupportTicketController::class, 'store'])
            ->name('tickets.store');

        Route::middleware([LimitSupportPayload::class, AuthenticateSupportKey::class, 'throttle:kaman-support-live'])
            ->group(function (): void {
                Route::get('/devices/session', [DeviceSessionController::class, 'info'])->name('devices.session.info');
                Route::post('/devices/session', [DeviceSessionController::class, 'store'])->name('devices.session');
                Route::post('/devices/logs', [DeviceSessionController::class, 'storeLogs'])->name('devices.logs');
                Route::post('/devices/close', [DeviceSessionController::class, 'close'])->name('devices.close');
            });
    });
