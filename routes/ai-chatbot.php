<?php

use App\Http\Controllers\AiChatbot\ChatbotController;
use App\Http\Controllers\AiChatbot\ChatbotGreenApiWebhookController;
use App\Http\Controllers\AiChatbot\ChatbotInstanceController;
use App\Http\Controllers\AiChatbot\ChatbotMemberController;
use App\Http\Controllers\AiChatbot\ChatbotSettingsController;
use App\Http\Controllers\AiChatbot\ChatbotWorkspaceController;
use App\Http\Controllers\AiChatbot\RealtimeCallController;
use App\Http\Controllers\AiChatbot\VoiceCallController;
use App\Http\Controllers\AiChatbot\VoiceStreamController;
use App\Services\AiChatbot\ChatbotAuthorizationService;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::post('/ai-chatbot/webhook/greenapi/{token}', [ChatbotGreenApiWebhookController::class, 'handle'])
    ->withoutMiddleware([
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
        StartSession::class,
        ShareErrorsFromSession::class,
    ])
    ->name('ai-chatbot.greenapi.webhook');

Route::middleware(['web', 'auth', 'project:ai-chatbot'])
    ->prefix('ai-chatbot')
    ->name('ai-chatbot.')
    ->group(function () {
        Route::get('/', function (Request $request) {
            $authz = app(ChatbotAuthorizationService::class);
            $instance = $authz->firstAccessibleForUser($request->user());

            if ($instance === null) {
                return response()->view('ai-chatbot.empty', [
                    'instances' => collect(),
                ]);
            }

            // Single-access users land in the customer workspace; multi-instance keep studio.
            $accessible = $authz->instancesForUser($request->user());
            if ($accessible->count() === 1 && $instance->hasMalanIntegration()) {
                return redirect()->route('ai-chatbot.workspace.conversations', $instance);
            }

            return redirect()->route('ai-chatbot.instances.show', $instance);
        })->name('index');

        Route::prefix('instances/{instance}')->group(function () {
            Route::get('/', [ChatbotController::class, 'index'])->name('instances.show');
            Route::get('/prompt', [ChatbotInstanceController::class, 'edit'])->name('instances.edit');
            Route::put('/prompt', [ChatbotInstanceController::class, 'update'])->name('instances.update');

            Route::post('/conversations', [ChatbotController::class, 'storeConversation'])->name('instances.conversations.store');
            Route::get('/conversations/{conversation}', [ChatbotController::class, 'showConversation'])->name('instances.conversations.show');
            Route::delete('/conversations/{conversation}', [ChatbotController::class, 'destroyConversation'])->name('instances.conversations.destroy');
            Route::post('/send', [ChatbotController::class, 'send'])->name('instances.send');
            Route::post('/upload-image', [ChatbotController::class, 'uploadImage'])->name('instances.upload-image');
            Route::get('/messages/{message}/attachment', [ChatbotController::class, 'attachment'])->name('instances.messages.attachment');

            Route::post('/voice/realtime/session', [RealtimeCallController::class, 'createSession'])->name('instances.voice.realtime.session');
            Route::post('/voice/realtime/{voiceCall}/connect', [RealtimeCallController::class, 'connect'])->name('instances.voice.realtime.connect');
            Route::post('/voice/realtime/{voiceCall}/events', [RealtimeCallController::class, 'storeEvents'])->name('instances.voice.realtime.events');
            Route::post('/voice/realtime/{voiceCall}/metrics', [RealtimeCallController::class, 'storeMetrics'])->name('instances.voice.realtime.metrics');
            Route::post('/voice/realtime/{voiceCall}/tools', [RealtimeCallController::class, 'executeTool'])->name('instances.voice.realtime.tools');
            Route::post('/voice/realtime/{voiceCall}/end', [RealtimeCallController::class, 'end'])->name('instances.voice.realtime.end');

            Route::get('/voice', [VoiceCallController::class, 'index'])->name('instances.voice.index');
            Route::post('/voice/start', [VoiceCallController::class, 'start'])->name('instances.voice.start');
            Route::post('/voice/tts', [VoiceCallController::class, 'synthesize'])->name('instances.voice.tts');
            Route::post('/voice/stream', [VoiceStreamController::class, 'converse'])->name('instances.voice.stream');
            Route::get('/voice/{voiceCall}', [VoiceCallController::class, 'show'])->name('instances.voice.show');
            Route::post('/voice/{voiceCall}/message', [VoiceCallController::class, 'sendMessage'])->name('instances.voice.message');
            Route::post('/voice/{voiceCall}/end', [VoiceCallController::class, 'end'])->name('instances.voice.end');

            Route::get('/members', [ChatbotMemberController::class, 'index'])->name('instances.members.index');
            Route::post('/members', [ChatbotMemberController::class, 'store'])->name('instances.members.store');
            Route::put('/members/{member}', [ChatbotMemberController::class, 'update'])->name('instances.members.update');
            Route::delete('/members/{member}', [ChatbotMemberController::class, 'destroy'])->name('instances.members.destroy');

            // Customer workspace
            Route::prefix('workspace')->name('workspace.')->group(function () {
                Route::get('/', [ChatbotWorkspaceController::class, 'index'])->name('index');
                Route::get('/conversations', [ChatbotWorkspaceController::class, 'conversations'])->name('conversations');
                Route::get('/conversations/poll', [ChatbotWorkspaceController::class, 'pollConversations'])->name('conversations.poll');
                Route::get('/conversations/{conversation}', [ChatbotWorkspaceController::class, 'showConversation'])->name('conversations.show');
                Route::get('/conversations/{conversation}/messages', [ChatbotWorkspaceController::class, 'pollMessages'])->name('conversations.messages');
                Route::post('/conversations/{conversation}/read', [ChatbotWorkspaceController::class, 'markRead'])->name('conversations.read');
                Route::post('/conversations/{conversation}/reply', [ChatbotWorkspaceController::class, 'reply'])->name('conversations.reply');
                Route::post('/conversations/{conversation}/bot-mode', [ChatbotWorkspaceController::class, 'updateBotMode'])->name('conversations.bot-mode');

                Route::get('/conversations/{conversation}/instructions', [ChatbotWorkspaceController::class, 'listInstructions'])->name('conversations.instructions.index');
                Route::post('/conversations/{conversation}/instructions', [ChatbotWorkspaceController::class, 'storeInstruction'])->name('conversations.instructions.store');
                Route::put('/conversations/{conversation}/instructions/{instruction}', [ChatbotWorkspaceController::class, 'updateInstruction'])->name('conversations.instructions.update');
                Route::post('/conversations/{conversation}/instructions/{instruction}/toggle', [ChatbotWorkspaceController::class, 'toggleInstruction'])->name('conversations.instructions.toggle');
                Route::delete('/conversations/{conversation}/instructions/{instruction}', [ChatbotWorkspaceController::class, 'destroyInstruction'])->name('conversations.instructions.destroy');

                Route::get('/settings', [ChatbotWorkspaceController::class, 'settings'])->name('settings');
                Route::put('/settings', [ChatbotWorkspaceController::class, 'updateSettings'])->name('settings.update');
                Route::post('/bot-active', [ChatbotWorkspaceController::class, 'updateBotActive'])->name('bot-active');
                Route::get('/test', [ChatbotWorkspaceController::class, 'testPage'])->name('test.page');
                Route::post('/test', [ChatbotWorkspaceController::class, 'test'])->name('test');
                Route::post('/test/image', [ChatbotWorkspaceController::class, 'testImage'])->name('test.image');
            });
        });

        Route::middleware('admin')->group(function () {
            Route::get('/admin/settings', [ChatbotSettingsController::class, 'edit'])->name('admin.settings.edit');
            Route::post('/admin/settings', [ChatbotSettingsController::class, 'update'])->name('admin.settings.update');
        });
    });
