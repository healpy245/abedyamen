<?php

use App\Http\Controllers\AiChatbot\ChatbotController;
use App\Http\Controllers\AiChatbot\ChatbotSettingsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'ai-chatbot.auth'])
    ->prefix('ai-chatbot')
    ->name('ai-chatbot.')
    ->group(function () {
        Route::get('/', [ChatbotController::class, 'index'])->name('index');
        Route::post('/conversations', [ChatbotController::class, 'storeConversation'])->name('conversations.store');
        Route::get('/conversations/{conversation}', [ChatbotController::class, 'showConversation'])->name('conversations.show');
        Route::delete('/conversations/{conversation}', [ChatbotController::class, 'destroyConversation'])->name('conversations.destroy');
        Route::post('/send', [ChatbotController::class, 'send'])->name('send');

        Route::middleware('admin')->group(function () {
            Route::get('/admin/settings', [ChatbotSettingsController::class, 'edit'])->name('admin.settings.edit');
            Route::post('/admin/settings', [ChatbotSettingsController::class, 'update'])->name('admin.settings.update');
        });
    });

