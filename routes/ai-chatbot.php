<?php

use App\Http\Controllers\AiChatbot\ChatbotController;
use App\Http\Controllers\AiChatbot\ChatbotGreenApiWebhookController;
use App\Http\Controllers\AiChatbot\ChatbotInstanceController;
use App\Http\Controllers\AiChatbot\ChatbotMemberController;
use App\Http\Controllers\AiChatbot\ChatbotSettingsController;
use App\Http\Controllers\AiChatbot\ChatbotWorkspaceController;
use App\Http\Controllers\AiChatbot\KamanPosDemoController;
use App\Http\Controllers\AiChatbot\MalanCampaignController;
use App\Http\Controllers\AiChatbot\MalanCampaignWebhookController;
use App\Http\Controllers\AiChatbot\RealtimeCallController;
use App\Http\Controllers\AiChatbot\VoiceCallController;
use App\Http\Controllers\AiChatbot\VoiceStreamController;
use App\Http\Middleware\ExtendUploadTimeout;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Route;
use Illuminate\View\Middleware\ShareErrorsFromSession;

Route::post('/ai-chatbot/webhook/greenapi/campaign/{token}', [MalanCampaignWebhookController::class, 'handle'])
    ->withoutMiddleware([
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
        StartSession::class,
        ShareErrorsFromSession::class,
    ])
    ->name('ai-chatbot.greenapi.campaign-webhook');

Route::post('/ai-chatbot/webhook/greenapi/{token}', [ChatbotGreenApiWebhookController::class, 'handle'])
    ->withoutMiddleware([
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
        StartSession::class,
        ShareErrorsFromSession::class,
    ])
    ->name('ai-chatbot.greenapi.webhook');

Route::get('/ai-chatbot/public/pos-demo/{token}', [KamanPosDemoController::class, 'show'])
    ->where('token', '[A-Za-z0-9]{32,64}')
    ->name('ai-chatbot.public.pos-demo');

Route::middleware(['web', 'auth', 'project:ai-chatbot'])
    ->prefix('ai-chatbot')
    ->name('ai-chatbot.')
    ->group(function () {
        Route::get('/', [ChatbotController::class, 'landing'])->name('index');

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
                Route::post('/settings/pos-demo', [ChatbotWorkspaceController::class, 'storePosDemo'])
                    ->middleware(ExtendUploadTimeout::class)
                    ->name('settings.pos-demo');
                Route::delete('/settings/pos-demo', [ChatbotWorkspaceController::class, 'destroyPosDemo'])->name('settings.pos-demo.destroy');
                Route::post('/settings/clear-conversations', [ChatbotWorkspaceController::class, 'clearConversations'])->name('settings.clear-conversations');
                Route::post('/bot-active', [ChatbotWorkspaceController::class, 'updateBotActive'])->name('bot-active');
                Route::get('/test', [ChatbotWorkspaceController::class, 'testPage'])->name('test.page');
                Route::post('/test', [ChatbotWorkspaceController::class, 'test'])->name('test');
                Route::post('/test/image', [ChatbotWorkspaceController::class, 'testImage'])->name('test.image');

                Route::get('/campaigns', [MalanCampaignController::class, 'index'])->name('campaigns');
                Route::post('/campaigns', [MalanCampaignController::class, 'store'])->name('campaigns.store');
                Route::get('/campaigns/{campaign}', [MalanCampaignController::class, 'show'])->name('campaigns.show');
                Route::get('/campaigns/{campaign}/conversations/poll', [MalanCampaignController::class, 'pollConversations'])->name('campaigns.conversations.poll');
                Route::get('/campaigns/{campaign}/conversations/{conversation}/messages', [MalanCampaignController::class, 'pollMessages'])->name('campaigns.conversations.messages');
                Route::post('/campaigns/{campaign}/conversations/{conversation}/bot-mode', [MalanCampaignController::class, 'updateBotMode'])->name('campaigns.conversations.bot-mode');
                Route::post('/campaigns/{campaign}/conversations/{conversation}/read', [MalanCampaignController::class, 'markRead'])->name('campaigns.conversations.read');
                Route::post('/campaigns/{campaign}/conversations/{conversation}/reply', [MalanCampaignController::class, 'reply'])->name('campaigns.conversations.reply');
                Route::get('/campaigns/{campaign}/analytics', [MalanCampaignController::class, 'pollAnalytics'])->name('campaigns.analytics');
                Route::get('/campaigns/{campaign}/analytics/export/{group}', [MalanCampaignController::class, 'exportAnalytics'])->name('campaigns.analytics.export');
                Route::get('/campaigns/{campaign}/settings', [MalanCampaignController::class, 'settings'])->name('campaigns.settings');
                Route::put('/campaigns/{campaign}/settings', [MalanCampaignController::class, 'updateSettings'])->name('campaigns.settings.update');
                Route::post('/campaigns/{campaign}/bot-active', [MalanCampaignController::class, 'updateBotActive'])->name('campaigns.bot-active');
                Route::get('/campaigns/{campaign}/test', [MalanCampaignController::class, 'testPage'])->name('campaigns.test.page');
                Route::post('/campaigns/{campaign}/test', [MalanCampaignController::class, 'test'])->name('campaigns.test');
                Route::post('/campaigns/{campaign}/start', [MalanCampaignController::class, 'start'])->name('campaigns.start');
                Route::get('/campaigns/{campaign}/contacts', [MalanCampaignController::class, 'contactsJson'])->name('campaigns.contacts');
                Route::post('/campaigns/{campaign}/contacts', [MalanCampaignController::class, 'storeContact'])->name('campaigns.contacts.store');
                Route::post('/campaigns/{campaign}/stop', [MalanCampaignController::class, 'stop'])->name('campaigns.stop');
            });
        });

        Route::middleware('admin')->group(function () {
            Route::get('/admin/settings', [ChatbotSettingsController::class, 'edit'])->name('admin.settings.edit');
            Route::post('/admin/settings', [ChatbotSettingsController::class, 'update'])->name('admin.settings.update');
        });
    });
