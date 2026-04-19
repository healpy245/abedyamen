<?php

use App\Http\Controllers\ChatLoginController;
use App\Http\Controllers\FormController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\WhatsAppBotController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/form', [FormController::class, 'index'])->name('form.index');
Route::post('/form', [FormController::class, 'submit'])->name('form.submit');
Route::post('/form/full-ai/login', [FormController::class, 'loginFullAi'])->name('form.full-ai.login');
Route::post('/form/full-ai/chat', [FormController::class, 'chatFullAutomation'])->name('form.full-ai.chat');
Route::post('/form/full-ai/execute-step', [FormController::class, 'executeFullAiStep'])->name('form.full-ai.execute-step');
Route::post('/form/full-ai/start', [FormController::class, 'startFullAutomation'])->name('form.full-ai.start');
Route::post('/form/full-ai/{session}/approve', [FormController::class, 'approveFullAutomation'])->name('form.full-ai.approve');
Route::get('/lang/{locale}', [FormController::class, 'setLocale'])->name('lang.switch');

Route::get('/landing', [LandingController::class, 'show'])->name('landing.show');
Route::post('/landing', [LandingController::class, 'submit'])->name('landing.submit');

// API route for image upload
Route::post('/api/upload-image', [FormController::class, 'uploadImage'])->name('api.upload-image');

// ChatGPT-style login assistant
Route::get('/ai/login-chat', [ChatLoginController::class, 'index'])->name('ai.login-chat.index');
Route::post('/ai/login-chat/message', [ChatLoginController::class, 'message'])->name('ai.login-chat.message');

// WhatsApp chatbot + Green API webhook
Route::get('/whatsapp-bot', [WhatsAppBotController::class, 'index'])->name('whatsapp.bot.index');
Route::get('/whatsapp-bot/events', [WhatsAppBotController::class, 'events'])->name('whatsapp.bot.events');
Route::post('/whatsapp-bot/prompt/save', [WhatsAppBotController::class, 'savePrompt'])->name('whatsapp.bot.prompt.save');
Route::post('/whatsapp-bot/prompt/reset', [WhatsAppBotController::class, 'resetPrompt'])->name('whatsapp.bot.prompt.reset');
Route::post('/whatsapp-bot/toggle', [WhatsAppBotController::class, 'toggleWebhook'])->name('whatsapp.bot.toggle');
Route::post('/whatsapp-bot/events/clear', [WhatsAppBotController::class, 'clearEvents'])->name('whatsapp.bot.events.clear');
Route::post('/whatsapp-bot/test-send', [WhatsAppBotController::class, 'testSend'])->name('whatsapp.bot.test-send');
Route::post('/whatsapp-bot/webhook', [WhatsAppBotController::class, 'webhook'])
    ->withoutMiddleware([
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
        StartSession::class,
        ShareErrorsFromSession::class,
    ])
    ->name('whatsapp.bot.webhook');
