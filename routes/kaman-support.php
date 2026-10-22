<?php

declare(strict_types=1);

use App\Http\Controllers\Api\KamanSupport\TicketCommandController;
use App\Http\Controllers\Api\KamanSupport\TicketController as SupportTicketApiController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\FormMenuAgentController;
use App\Http\Controllers\KamanSupport\ConsoleController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'project:kaman-support', 'pos-live-scope'])
    ->prefix('kaman-support')
    ->name('kaman-support.')
    ->group(function (): void {
        Route::get('/', [ConsoleController::class, 'index'])->name('index');
        Route::get('/logos', [ConsoleController::class, 'logos'])->name('logos');
        Route::get('/logs', [ConsoleController::class, 'logs'])->name('logs');
        Route::get('/menu', [ConsoleController::class, 'menu'])->name('menu');
        Route::post('/menu/auth', [FormController::class, 'checkFullAiAuth'])->name('menu.auth');
        Route::post('/menu/login', [FormController::class, 'loginFullAi'])->name('menu.login');
        Route::post('/menu/chat', [FormMenuAgentController::class, 'chat'])->name('menu.chat');
        Route::get('/inbox', [ConsoleController::class, 'inbox'])->name('inbox');
        Route::get('/live', [ConsoleController::class, 'live'])->name('live');
        Route::get('/liveview', [ConsoleController::class, 'live'])->name('liveview');
        Route::get('/live/feed', [ConsoleController::class, 'liveFeed'])->name('live.feed');
        Route::get('/failed', [ConsoleController::class, 'failed'])->name('failed');
        Route::get('/failed/feed', [ConsoleController::class, 'failedFeed'])->name('failed.feed');
        Route::get('/live/{device}/feed', [ConsoleController::class, 'deviceFeed'])->name('device.feed');
        Route::get('/live/{device}', [ConsoleController::class, 'device'])->name('device');
        Route::get('/tickets/{ticket}', [ConsoleController::class, 'show'])->name('show');
        Route::patch('/tickets/{ticket}', [ConsoleController::class, 'update'])->name('update');
        Route::post('/tickets/{ticket}/close', [ConsoleController::class, 'close'])->name('close');
        Route::post('/tickets/{ticket}/escalate', [ConsoleController::class, 'escalate'])->name('escalate');
        Route::post('/tickets/{ticket}/events', [ConsoleController::class, 'storeEvent'])->name('events.store');
        Route::post('/tickets/{ticket}/commands', [ConsoleController::class, 'storeTicketCommand'])->name('tickets.commands');
        Route::post('/live/{device}/commands', [ConsoleController::class, 'storeDeviceCommand'])->name('device.commands');
    });

Route::middleware(['auth', 'project:kaman-support', 'pos-live-scope'])
    ->prefix('api/kaman-support/v1')
    ->name('kaman-support.api.')
    ->group(function (): void {
        Route::get('/tickets', [SupportTicketApiController::class, 'index'])->name('tickets.index');
        Route::get('/tickets/{ticket}', [SupportTicketApiController::class, 'show'])->name('tickets.show');
        Route::patch('/tickets/{ticket}', [SupportTicketApiController::class, 'update'])->name('tickets.update');
        Route::post('/tickets/{ticket}/events', [SupportTicketApiController::class, 'storeEvent'])->name('tickets.events');
        Route::post('/tickets/{ticket}/commands', [TicketCommandController::class, 'store'])->name('tickets.commands');
    });
