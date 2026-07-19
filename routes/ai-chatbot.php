<?php

use App\Http\Controllers\AiChatbot\ChatbotController;
use App\Http\Controllers\AiChatbot\ChatbotInstanceController;
use App\Http\Controllers\AiChatbot\ChatbotMemberController;
use App\Http\Controllers\AiChatbot\ChatbotSettingsController;
use App\Services\AiChatbot\AiChatbotInstanceService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'project:ai-chatbot'])
    ->prefix('ai-chatbot')
    ->name('ai-chatbot.')
    ->group(function () {
        Route::get('/', function (Request $request) {
            $instance = app(AiChatbotInstanceService::class)->ensureDefaultForUser($request->user());

            return redirect()->route('ai-chatbot.instances.show', $instance);
        })->name('index');

        Route::post('/instances', [ChatbotInstanceController::class, 'store'])->name('instances.store');

        Route::prefix('instances/{instance}')->group(function () {
            Route::get('/', [ChatbotController::class, 'index'])->name('instances.show');
            Route::get('/prompt', [ChatbotInstanceController::class, 'edit'])->name('instances.edit');
            Route::put('/prompt', [ChatbotInstanceController::class, 'update'])->name('instances.update');
            Route::delete('/', [ChatbotInstanceController::class, 'destroy'])->name('instances.destroy');

            Route::post('/conversations', [ChatbotController::class, 'storeConversation'])->name('instances.conversations.store');
            Route::get('/conversations/{conversation}', [ChatbotController::class, 'showConversation'])->name('instances.conversations.show');
            Route::delete('/conversations/{conversation}', [ChatbotController::class, 'destroyConversation'])->name('instances.conversations.destroy');
            Route::post('/send', [ChatbotController::class, 'send'])->name('instances.send');

            Route::get('/members', [ChatbotMemberController::class, 'index'])->name('instances.members.index');
            Route::post('/members', [ChatbotMemberController::class, 'store'])->name('instances.members.store');
            Route::put('/members/{member}', [ChatbotMemberController::class, 'update'])->name('instances.members.update');
            Route::delete('/members/{member}', [ChatbotMemberController::class, 'destroy'])->name('instances.members.destroy');
        });

        Route::middleware('admin')->group(function () {
            Route::get('/admin/settings', [ChatbotSettingsController::class, 'edit'])->name('admin.settings.edit');
            Route::post('/admin/settings', [ChatbotSettingsController::class, 'update'])->name('admin.settings.update');
        });
    });
