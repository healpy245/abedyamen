<?php

use App\Http\Controllers\Api\OfficeVision\OfficeVisionController;
use App\Http\Middleware\OfficeVision\AuthenticateOfficeVision;
use Illuminate\Support\Facades\Route;

Route::post('/pair', [OfficeVisionController::class, 'pair'])
    ->middleware('throttle:20,1')
    ->name('officevision.pair');

Route::middleware([AuthenticateOfficeVision::class, 'throttle:120,1'])->group(function (): void {
    Route::post('/events', [OfficeVisionController::class, 'storeEvent'])->name('officevision.events.store');
    Route::get('/events', [OfficeVisionController::class, 'events'])->name('officevision.events');
    Route::get('/events/{id}/clip', [OfficeVisionController::class, 'clip'])->whereNumber('id')->name('officevision.events.clip');
    Route::post('/heartbeat', [OfficeVisionController::class, 'heartbeat'])->name('officevision.heartbeat');
    Route::get('/cameras', [OfficeVisionController::class, 'cameras'])->name('officevision.cameras');
    Route::get('/streams', [OfficeVisionController::class, 'streams'])->name('officevision.streams');
});
