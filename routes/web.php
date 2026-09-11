<?php

use App\Http\Controllers\FormController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LocaleController;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

Route::get('/login', function () {
    return view('auth.login');
})->name('login');

Route::post('/login', function (Illuminate\Http\Request $request) {
    $credentials = $request->validate([
        'email' => ['required', 'email'],
        'password' => ['required', 'string'],
    ]);

    if (Auth::attempt($credentials, $request->boolean('remember'))) {
        $request->session()->regenerate();

        return redirect()->intended(route('home'));
    }

    return back()
        ->withErrors(['email' => __('auth.failed')])
        ->onlyInput('email');
});

Route::post('/logout', function (Illuminate\Http\Request $request) {
    Auth::logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

Route::middleware('auth')->group(function () {
    Route::get('/', function () {
        return view('welcome');
    })->name('home');
});

Route::get('/ops/clear/483275634', function () {
    $commands = [
        'optimize:clear',
        'config:clear',
        'cache:clear',
        'route:clear',
        'view:clear',
        'event:clear',
    ];

    $results = [];

    foreach ($commands as $command) {
        try {
            $exitCode = Artisan::call($command);
            $results[$command] = [
                'ok' => $exitCode === 0,
                'exit_code' => $exitCode,
                'output' => trim(Artisan::output()),
            ];
        } catch (\Throwable $e) {
            $results[$command] = [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    $allOk = collect($results)->every(fn (array $r) => ($r['ok'] ?? false) === true);

    $opcacheReset = function_exists('opcache_reset') ? opcache_reset() : null;

    return response()->json([
        'success' => $allOk,
        'message' => $allOk
            ? 'All clear commands completed.'
            : 'Some clear commands failed. See results.',
        'results' => $results,
        'opcache_reset' => $opcacheReset,
        'category_and_meal_workflow_class_exists' => class_exists(\App\Services\AI\Workflows\CategoryAndMealStoreWorkflow::class),
        'timestamp' => now()->toIso8601String(),
    ], $allOk ? 200 : 500);
})
    ->withoutMiddleware([
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
    ])
    ->name('ops.clear');

Route::get('/ops/migrate/483275634', function () {
    try {
        $exitCode = Artisan::call('migrate', ['--force' => true]);
        $output = trim(Artisan::output());
        $ok = $exitCode === 0;

        return response()->json([
            'success' => $ok,
            'message' => $ok ? 'Migrations completed.' : 'Migration failed.',
            'exit_code' => $exitCode,
            'output' => $output,
            'timestamp' => now()->toIso8601String(),
        ], $ok ? 200 : 500);
    } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
            'message' => $e->getMessage(),
            'timestamp' => now()->toIso8601String(),
        ], 500);
    }
})
    ->withoutMiddleware([
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
    ])
    ->name('ops.migrate');

Route::get('/ops/seed/483275636', function () {
    try {
        $results = [];

        foreach ([
            \Database\Seeders\WorkspaceUserSeeder::class,
            \Database\Seeders\AppDevelopmentMemberSeeder::class,
            \Database\Seeders\SpeedcomChatbotInstanceSeeder::class,
            \Database\Seeders\KamanWhatsappChatbotInstanceSeeder::class,
            \Database\Seeders\KamanCompanyMemberSeeder::class,
        ] as $seeder) {
            $exitCode = Artisan::call('db:seed', [
                '--class' => $seeder,
                '--force' => true,
            ]);
            $results[$seeder] = [
                'ok' => $exitCode === 0,
                'exit_code' => $exitCode,
                'output' => trim(Artisan::output()),
            ];
        }

        $ok = collect($results)->every(fn (array $r) => ($r['ok'] ?? false) === true);

        return response()->json([
            'success' => $ok,
            'message' => $ok ? 'Seeders completed.' : 'Seeding failed.',
            'results' => $results,
            'timestamp' => now()->toIso8601String(),
        ], $ok ? 200 : 500);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => $e->getMessage(),
            'timestamp' => now()->toIso8601String(),
        ], 500);
    }
})
    ->withoutMiddleware([
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
    ])
    ->name('ops.seed');

Route::get('/ops/migrate-fresh-seed/9274618053', function () {
    @set_time_limit(0);
    @ini_set('max_execution_time', '0');
    @ini_set('memory_limit', '512M');

    $results = [];

    try {
        $connection = Schema::getConnection();
        $database = $connection->getDatabaseName();
        $driver = $connection->getDriverName();

        if ($driver === 'mysql') {
            $connection->statement('SET FOREIGN_KEY_CHECKS=0');
            $tables = $connection->select('SHOW FULL TABLES WHERE Table_type = ?', ['BASE TABLE']);
            $key = 'Tables_in_'.$database;
            foreach ($tables as $table) {
                $name = $table->{$key} ?? null;
                if (is_string($name) && $name !== '') {
                    $connection->statement('DROP TABLE IF EXISTS `'.$name.'`');
                }
            }
            $connection->statement('SET FOREIGN_KEY_CHECKS=1');
            $results['wipe'] = [
                'ok' => true,
                'dropped' => count($tables),
            ];
        } else {
            $wipeExit = Artisan::call('db:wipe', ['--force' => true]);
            $results['wipe'] = [
                'ok' => $wipeExit === 0,
                'exit_code' => $wipeExit,
            'output' => trim(Artisan::output()),
        ];
        }
    } catch (\Throwable $e) {
        $results['wipe'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }

    if (($results['wipe']['ok'] ?? false) !== true) {
        return response()->json([
            'success' => false,
            'message' => 'Database wipe failed before migrate/seed.',
            'results' => $results,
            'timestamp' => now()->toIso8601String(),
        ], 500);
    }

    try {
        $migrateExit = Artisan::call('migrate', [
            '--force' => true,
            '--seed' => true,
        ]);
        $results['migrate --seed'] = [
            'ok' => $migrateExit === 0,
            'exit_code' => $migrateExit,
            'output' => trim(Artisan::output()),
        ];
    } catch (\Throwable $e) {
        $results['migrate --seed'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }

    $allOk = collect($results)->every(fn (array $r) => ($r['ok'] ?? false) === true);

    return response()->json([
        'success' => $allOk,
        'message' => $allOk
            ? 'Database wiped and migrate --seed completed.'
            : 'migrate/seed failed. See results.',
        'results' => $results,
        'timestamp' => now()->toIso8601String(),
    ], $allOk ? 200 : 500);
})
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->name('ops.migrate-fresh-seed');

Route::get('/ops/malan-greenapi/9274618053', function () {
    $instance = \App\Models\AiChatbot\ChatbotInstance::query()
        ->where('integration_type', 'malan')
        ->where('name', \Database\Seeders\SallyMalanChatbotInstanceSeeder::INSTANCE_NAME)
        ->orderBy('id')
        ->first();

    if ($instance === null) {
        return response()->json([
            'success' => false,
            'message' => 'Malan chatbot instance not found.',
        ], 404);
    }

    $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
    $settings['enabled'] = true;
    $settings['label'] = $settings['label'] ?? 'Sally — Malan Internet CRM';
    $settings['allowed_reply_phones'] = [
        '0533046830',
        '0524060606',
    ];

    $instance->forceFill([
        'greenapi_url' => 'https://7107.api.greenapi.com/waInstance7107621968/sendMessage/e8f81a4913314e39b52c24dbd1f0ae440e90eb90e273475d97',
        'integration_settings' => $settings,
        'is_active' => true,
    ])->save();

    $webhookUrl = app(\App\Services\AiChatbot\ChatbotGreenApiService::class)->webhookUrl($instance);

    return response()->json([
        'success' => true,
        'message' => 'Malan Green API linked with reply allowlist.',
        'instance_id' => $instance->id,
        'greenapi_url_set' => filled($instance->greenapi_url),
        'allowed_reply_phones' => $instance->allowedReplyPhones(),
        'webhook_url' => $webhookUrl,
        'timestamp' => now()->toIso8601String(),
    ]);
})
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->name('ops.malan-greenapi');

Route::get('/ops/malan-refresh-prompt/9274618053', function () {
    $instance = \App\Models\AiChatbot\ChatbotInstance::query()
        ->where('integration_type', 'malan')
        ->where('name', \Database\Seeders\SallyMalanChatbotInstanceSeeder::INSTANCE_NAME)
        ->orderBy('id')
        ->first();

    if ($instance === null) {
        return response()->json([
            'success' => false,
            'message' => 'Malan chatbot instance not found.',
        ], 404);
    }

    $compiler = app(\App\Services\AiChatbot\PromptCompiler::class);
    $sections = $compiler->sallyMalanDefaultSections();
    $fallbackPath = database_path('seeders/prompts/sally_malan_system_prompt.txt');
    $fallback = is_file($fallbackPath) ? trim((string) file_get_contents($fallbackPath)) : '';
    $compiled = $compiler->compile($sections, $fallback);

    $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
    $settings['enabled'] = $settings['enabled'] ?? true;
    $settings['label'] = $settings['label'] ?? 'Sally — Malan Internet CRM';

    $instance->forceFill([
        'system_prompt' => $compiled !== '' ? $compiled : $fallback,
        'prompt_sections' => $sections,
        'settings_schema_version' => \App\Services\AiChatbot\PromptCompiler::SCHEMA_VERSION,
        'integration_settings' => $settings,
    ])->save();

    return response()->json([
        'success' => true,
        'message' => 'Malan prompt refreshed from seeder sections.',
        'instance_id' => $instance->id,
        'prompt_chars' => mb_strlen((string) $instance->system_prompt),
        'sections' => array_keys($sections),
        'timestamp' => now()->toIso8601String(),
    ]);
})
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->name('ops.malan-refresh-prompt');

Route::get('/ops/clear-conversations/9274618053/{instance?}', function (?int $instance = null) {
    $target = $instance
        ? \App\Models\AiChatbot\ChatbotInstance::query()->find($instance)
        : \App\Models\AiChatbot\ChatbotInstance::query()
            ->where('integration_type', 'malan')
            ->orderBy('id')
            ->first();

    if ($target === null) {
        return response()->json([
            'success' => false,
            'message' => 'Chatbot instance not found.',
        ], 404);
    }

    $before = (int) $target->conversations()->count();
    $result = $target->clearAllConversations();

    try {
        \Illuminate\Support\Facades\Artisan::call('cache:clear');
    } catch (\Throwable $e) {
        // ignore cache clear failures
    }

    return response()->json([
        'success' => true,
        'message' => 'Conversations cleared. Instance settings kept.',
        'instance_id' => $target->id,
        'instance_name' => $target->name,
        'conversations_before' => $before,
        'conversations_deleted' => $result['conversations'],
        'conversations_remaining' => (int) $target->conversations()->count(),
        'timestamp' => now()->toIso8601String(),
    ]);
})
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->name('ops.clear-conversations');

Route::get('/ops/malan-whatsapp-status/9274618053', function () {
    $instance = \App\Models\AiChatbot\ChatbotInstance::query()->find(1)
        ?? \App\Models\AiChatbot\ChatbotInstance::query()
            ->where('integration_type', 'malan')
            ->orderBy('id')
            ->first();

    if ($instance === null) {
        return response()->json(['success' => false, 'message' => 'Instance not found.'], 404);
    }

    $activate = request()->boolean('activate');
    $clearAllowlist = request()->boolean('clear_allowlist');
    $resetModes = request()->boolean('reset_modes');
    $changed = [];

    if ($activate && ! $instance->isBotGloballyActive()) {
        $instance->forceFill(['is_active' => true])->save();
        $changed[] = 'activated_bot';
    }

    if ($clearAllowlist && $instance->hasReplyPhoneAllowlist()) {
        $settings = is_array($instance->integration_settings) ? $instance->integration_settings : [];
        $settings['allowed_reply_phones'] = [];
        $instance->forceFill(['integration_settings' => $settings])->save();
        $changed[] = 'cleared_allowlist';
    }

    if ($resetModes) {
        $updated = $instance->conversations()
            ->whereIn('bot_mode', [
                \App\Models\AiChatbot\ChatbotConversation::BOT_MODE_HUMAN_TAKEOVER,
                \App\Models\AiChatbot\ChatbotConversation::BOT_MODE_PAUSED,
            ])
            ->update([
                'bot_mode' => \App\Models\AiChatbot\ChatbotConversation::BOT_MODE_ACTIVE,
                'assigned_user_id' => null,
            ]);
        $changed[] = 'reset_modes:'.$updated;
    }

    if (request()->boolean('typing_faster')) {
        foreach ([
            'typing_reference_seconds' => '5',
            'typing_min_seconds' => '1',
            'typing_max_seconds' => '8',
        ] as $key => $value) {
            \App\Models\AiChatbot\ChatbotSetting::updateOrCreate(
                ['key' => $key],
                ['value' => $value],
            );
        }
        $changed[] = 'typing_faster';
    }

    $instance->refresh();

    $conversations = $instance->conversations()
        ->orderByDesc('id')
        ->limit(12)
        ->get(['id', 'channel', 'bot_mode', 'contact_phone', 'contact_name', 'unread_count', 'last_message_at', 'created_at']);

    $recentMessages = \App\Models\AiChatbot\ChatbotMessage::query()
        ->whereIn('conversation_id', $conversations->pluck('id')->all() ?: [0])
        ->orderByDesc('id')
        ->limit(20)
        ->get(['id', 'conversation_id', 'role', 'reply_source', 'message_type', 'created_at']);

    $webhookUrl = app(\App\Services\AiChatbot\ChatbotGreenApiService::class)->webhookUrl($instance);

    $payload = [
        'success' => true,
        'changed' => $changed,
        'instance' => [
            'id' => $instance->id,
            'name' => $instance->name,
            'is_active' => $instance->isBotGloballyActive(),
            'integration_type' => $instance->integration_type,
            'has_greenapi_url' => filled($instance->greenapi_url),
            'webhook_token_set' => filled($instance->greenapi_webhook_token),
            'webhook_url' => $webhookUrl,
            'allowed_reply_phones' => $instance->allowedReplyPhones(),
            'ignored_reply_phones' => $instance->ignoredReplyPhones(),
        ],
        'conversations' => $conversations,
        'recent_messages' => $recentMessages,
        'hints' => [
            'If is_active is false, WhatsApp will store messages but not auto-reply.',
            'If allowed_reply_phones is non-empty, only those numbers get auto-replies.',
            'Use ?activate=1 to turn the bot on. Use ?clear_allowlist=1 to reply to everyone.',
            'Confirm Green API console webhook URL matches webhook_url above.',
            'Use ?debug_conversation=ID to dump messages/tools/context for one chat.',
        ],
        'timestamp' => now()->toIso8601String(),
    ];

    $debugConversationId = (int) request()->query('debug_conversation', 0);
    if ($debugConversationId > 0) {
        $debugConversation = $instance->conversations()->where('id', $debugConversationId)->first();
        if ($debugConversation !== null) {
            $payload['debug'] = [
                'conversation' => $debugConversation->only([
                    'id', 'channel', 'bot_mode', 'contact_phone', 'contact_name', 'created_at', 'last_message_at',
                ]),
                'context' => \App\Models\AiChatbot\ChatbotConversationContext::query()
                    ->where('conversation_id', $debugConversationId)
                    ->first(),
                'messages' => \App\Models\AiChatbot\ChatbotMessage::query()
                    ->where('conversation_id', $debugConversationId)
                    ->orderBy('id')
                    ->get(['id', 'role', 'reply_source', 'message', 'created_at']),
                'tools' => \App\Models\AiChatbot\ChatbotToolExecution::query()
                    ->where('conversation_id', $debugConversationId)
                    ->orderBy('id')
                    ->get(['id', 'tool_name', 'success', 'arguments', 'result', 'created_at']),
                'support_reports' => \App\Models\Malan\MalanSupportReport::query()
                    ->where('conversation_id', $debugConversationId)
                    ->orderBy('id')
                    ->get(),
            ];
        } else {
            $payload['debug'] = ['error' => 'conversation_not_found'];
        }
    }

    return response()->json($payload);
})
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ])
    ->name('ops.malan-whatsapp-status');

Route::get('/ops/malan-campaign-debug/9274618053/{campaign?}', function (?int $campaign = null) {
    $campaignId = $campaign ?: (int) request()->query('campaign', 1);
    $model = \App\Models\Malan\MalanCampaign::query()->with('contacts')->find($campaignId);
    if ($model === null) {
        return response()->json(['success' => false, 'message' => 'Campaign not found.'], 404);
    }

    $resend = request()->boolean('resend');
    $refreshPrompt = request()->boolean('refresh_prompt');
    $testChatId = trim((string) request()->query('test_chat_id', ''));
    $actions = [];

    $green = app(\App\Services\AiChatbot\ChatbotGreenApiService::class);
    $service = app(\App\Services\Malan\Campaigns\MalanCampaignService::class);

    if ($refreshPrompt) {
        $model->forceFill([
            'system_prompt' => \App\Models\Malan\MalanCampaign::defaultLeadSystemPrompt($model->instance),
        ])->save();
        $actions['refresh_prompt'] = [
            'ok' => true,
            'chars' => mb_strlen((string) $model->system_prompt),
        ];
        $model->refresh();
    }

    if (request()->boolean('reset_sales_mode')) {
        $contextService = app(\App\Services\Malan\MalanConversationContextService::class);
        $instance = $model->instance;
        $reset = 0;
        $clearedMessages = 0;
        if ($instance !== null) {
            foreach (\App\Models\AiChatbot\ChatbotConversation::query()->where('campaign_id', $model->id)->get() as $conversation) {
                $clearedMessages += \App\Models\AiChatbot\ChatbotMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->delete();
                $contextService->beginCampaignSales($conversation, $instance);
                $reset++;
            }
        }
        $actions['reset_sales_mode'] = [
            'conversations' => $reset,
            'messages_cleared' => $clearedMessages,
        ];
    }

    if (request()->boolean('clear_chats')) {
        $conversationQuery = \App\Models\AiChatbot\ChatbotConversation::query()
            ->where('campaign_id', $model->id);
        $before = (int) $conversationQuery->count();
        $messageCount = (int) \App\Models\AiChatbot\ChatbotMessage::query()
            ->whereIn('conversation_id', (clone $conversationQuery)->select('id'))
            ->count();

        // Wipe chat history and reset contact engagement so live analytics match.
        \App\Models\Malan\MalanCampaignContact::query()
            ->where('campaign_id', $model->id)
            ->update([
                'conversation_id' => null,
                'status' => \App\Models\Malan\MalanCampaignContact::STATUS_PENDING,
                'message_sent_at' => null,
                'responded_at' => null,
                'lead_created_at' => null,
                'last_error' => null,
            ]);

        $conversationQuery->delete();

        $model->forceFill([
            'messages_triggered_count' => 0,
            'responded_count' => 0,
            'leads_stored_count' => 0,
            'status' => \App\Models\Malan\MalanCampaign::STATUS_READY,
            'completed_at' => null,
            'stopped_at' => null,
        ])->save();

        $service->refreshAnalytics($model);
        $model->refresh();
        $model->load('contacts');

        try {
            \Illuminate\Support\Facades\Artisan::call('cache:clear');
        } catch (\Throwable $e) {
            // ignore
        }

        $actions['clear_chats'] = [
            'conversations_deleted' => $before,
            'messages_deleted_estimate' => $messageCount,
            'conversations_remaining' => (int) \App\Models\AiChatbot\ChatbotConversation::query()
                ->where('campaign_id', $model->id)
                ->count(),
            'analytics' => app(\App\Services\Malan\Campaigns\CampaignInsightService::class)->summarize($model),
        ];
    }

    if ($testChatId !== '' && filled($model->greenapi_url)) {
        $result = $green->sendMessage(
            trim((string) $model->greenapi_url),
            $testChatId,
            'اختبار إرسال من حملة '.$model->name.' — '.now()->toDateTimeString(),
        );
        $actions['test_send'] = $result;
    }

    if (request()->boolean('process_unanswered_bursts')) {
        $burstService = app(\App\Services\Malan\Campaigns\CampaignConversationBurstService::class);
        $processed = [];
        foreach (\App\Models\AiChatbot\ChatbotConversation::query()->where('campaign_id', $model->id)->get() as $conversation) {
            $burst = $burstService->collectUnprocessedBurst($conversation);
            if ($burst === []) {
                continue;
            }
            $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
            $chatId = trim((string) ($meta['whatsapp_chat_id'] ?? ''));
            if ($chatId === '') {
                $contact = $model->contacts->firstWhere('conversation_id', $conversation->id);
                $chatId = trim((string) ($contact?->chat_id ?? ''));
            }
            if ($chatId === '') {
                continue;
            }

            // Ensure an open burst + current version so Process accepts the job.
            $meta['campaign_burst_open'] = true;
            if (empty($meta['campaign_burst_started_at'])) {
                $meta['campaign_burst_started_at'] = now()->toIso8601String();
            }
            $version = ((int) ($meta['campaign_conversation_version'] ?? 0));
            if ($version < 1) {
                $version = 1;
            }
            $meta['campaign_conversation_version'] = $version;
            $meta['campaign_pending_process_version'] = $version;
            $conversation->forceFill(['metadata' => $meta])->save();

            $job = new \App\Jobs\ProcessCampaignConversationJob(
                (int) $model->id,
                (int) $conversation->id,
                $version,
                $chatId,
            );
            $job->handle(
                app(\App\Services\AiChatbot\AiChatbotService::class),
                app(\App\Services\AiChatbot\ChatbotGreenApiService::class),
                $burstService,
                app(\App\Services\Malan\MalanConversationContextService::class),
            );
            $processed[] = [
                'conversation_id' => $conversation->id,
                'version' => $version,
                'burst_count' => count($burst),
                'chat_id' => $chatId,
            ];
        }
        $actions['process_unanswered_bursts'] = $processed;
    }

    if (request()->boolean('flush_pending')) {
        $conversationIdsForFlush = $model->contacts->pluck('conversation_id')->filter()->all();
        $pending = \App\Models\AiChatbot\ChatbotMessage::query()
            ->whereIn('conversation_id', $conversationIdsForFlush ?: [0])
            ->where('role', 'assistant')
            ->where('delivery_status', 'pending')
            ->orderBy('id')
            ->get();

        $flushed = [];
        foreach ($pending as $msg) {
            $meta = is_array($msg->metadata) ? $msg->metadata : [];
            if (($meta['campaign_delivery'] ?? null) !== 'queued') {
                continue;
            }
            $chatId = trim((string) ($meta['greenapi_chat_id'] ?? ''));
            if ($chatId === '') {
                continue;
            }
            $triggerId = (int) ($meta['trigger_user_message_id'] ?? 0);
            $job = new \App\Jobs\SendDelayedCampaignWhatsAppJob(
                (int) $model->id,
                (int) $msg->id,
                $chatId,
                $triggerId,
            );
            $job->handle(app(\App\Services\AiChatbot\ChatbotGreenApiService::class));
            $msg->refresh();
            $flushed[] = [
                'message_id' => $msg->id,
                'delivery_status' => $msg->delivery_status,
                'campaign_delivery' => is_array($msg->metadata) ? ($msg->metadata['campaign_delivery'] ?? null) : null,
            ];
        }
        $actions['flush_pending'] = $flushed;
    }

    if ($resend) {
        $service->requeueAllContactsForResend($model);
        $model->forceFill([
            'status' => \App\Models\Malan\MalanCampaign::STATUS_RUNNING,
            'stopped_at' => null,
            'completed_at' => null,
        ])->save();

        $service->processUntilIdleOrStopped($model->fresh() ?? $model);
        $actions['resend'] = 'processed';
        $model->refresh();
        $model->load('contacts');
    }

    if (request()->boolean('resume_chats')) {
        $service->resumeCampaignConversations($model);
        $actions['resume_chats'] = true;
        $model->refresh();
    }

    if (request()->boolean('stop_loops')) {
        $stopped = 0;
        $paused = 0;
        foreach (\App\Models\Malan\MalanCampaign::query()->where('chatbot_instance_id', $model->chatbot_instance_id)->get() as $c) {
            app(\App\Services\Malan\Campaigns\MalanCampaignService::class)->stop($c);
            $stopped++;
            $paused += \App\Models\AiChatbot\ChatbotConversation::query()
                ->where('campaign_id', $c->id)
                ->where('bot_mode', \App\Models\AiChatbot\ChatbotConversation::BOT_MODE_PAUSED)
                ->count();
        }
        $actions['stop_loops'] = ['campaigns_stopped' => $stopped, 'paused_chats' => $paused];
        $model->refresh();
        $model->load('contacts');
    }

    if (request()->boolean('repair_sent')) {
        // Mark contacts as sent when a blast message already has greenapi idMessage.
        foreach ($model->contacts as $contact) {
            if ($contact->conversation_id === null) {
                continue;
            }
            $blast = \App\Models\AiChatbot\ChatbotMessage::query()
                ->where('conversation_id', $contact->conversation_id)
                ->where('role', 'assistant')
                ->orderByDesc('id')
                ->get()
                ->first(function ($m) {
                    $meta = is_array($m->metadata) ? $m->metadata : [];

                    return ! empty($meta['campaign_blast']) && ! empty($meta['greenapi_id_message']);
                });
            if ($blast === null) {
                continue;
            }
            $meta = is_array($blast->metadata) ? $blast->metadata : [];
            $contact->forceFill([
                'status' => $contact->responded_at
                    ? \App\Models\Malan\MalanCampaignContact::STATUS_RESPONDED
                    : \App\Models\Malan\MalanCampaignContact::STATUS_SENT,
                'chat_id' => $meta['greenapi_chat_id'] ?? $contact->chat_id,
                'message_sent_at' => $contact->message_sent_at ?? $blast->created_at,
                'last_error' => null,
            ])->save();
        }
        $service->refreshAnalytics($model);
        $actions['repair_sent'] = true;
        $model->refresh();
        $model->load('contacts');
    }

    $conversationIds = $model->contacts->pluck('conversation_id')->filter()->all();
    $messages = \App\Models\AiChatbot\ChatbotMessage::query()
        ->whereIn('conversation_id', $conversationIds ?: [0])
        ->orderByDesc('id')
        ->limit(20)
        ->get(['id', 'conversation_id', 'role', 'delivery_status', 'message', 'metadata', 'created_at']);

    $sendUrl = trim((string) $model->greenapi_url);
    $urlLooksValid = $sendUrl !== '' && str_contains($sendUrl, 'sendMessage');

    return response()->json([
        'success' => true,
        'actions' => $actions,
        'campaign' => [
            'id' => $model->id,
            'name' => $model->name,
            'status' => $model->status,
            'has_greenapi_url' => $sendUrl !== '',
            'greenapi_url_looks_valid' => $urlLooksValid,
            'greenapi_host' => $sendUrl !== '' ? (parse_url($sendUrl, PHP_URL_HOST) ?: null) : null,
            'greenapi_path' => $sendUrl !== '' ? (parse_url($sendUrl, PHP_URL_PATH) ?: null) : null,
            'webhook_url' => $service->webhookUrl($model),
            'analytics' => $model->analytics(),
            'opening_message_preview' => mb_substr((string) $model->opening_message, 0, 120),
        ],
        'contacts' => $model->contacts->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'phone' => $c->phone,
            'phone_normalized' => $c->phone_normalized,
            'chat_id' => $c->chat_id,
            'status' => $c->status,
            'message_sent_at' => $c->message_sent_at,
            'last_error' => $c->last_error,
            'conversation_id' => $c->conversation_id,
            'rebuilt_chat_id' => $service->rebuildChatIdForContact($c),
        ]),
        'messages' => $messages,
        'hints' => [
            'Use ?process_unanswered_bursts=1 to immediately AI-process stuck customer bursts and send WhatsApp.',
            'Use ?flush_pending=1 to immediately send stuck queued campaign WhatsApp replies.',
            'Use ?resend=1 to repair chatIds and resend opening messages.',
            'Use ?refresh_prompt=1 to replace system_prompt with the latest campaign sales default.',
            'Use ?reset_sales_mode=1 to force all campaign chats into campaign_sales_conversation (not new_signup_intake).',
            'Use ?clear_chats=1 to delete all campaign conversations/messages (Excel contacts kept).',
            'Use ?test_chat_id=9725XXXXXXXX@c.us to probe Green API credentials.',
            'chatId must be 972…@c.us (not 05… and not bare 5…).',
            'greenapi_url must be the full …/waInstance…/sendMessage/… URL for THIS campaign instance.',
        ],
        'timestamp' => now()->toIso8601String(),
    ]);
})
    ->withoutMiddleware([
        \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
        \Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class,
    ])
    ->name('ops.malan-campaign-debug');

Route::get('/landing', [LandingController::class, 'show'])->name('landing.show');
Route::post('/landing', [LandingController::class, 'submit'])->name('landing.submit');

Route::get('/lang/{locale}', [LocaleController::class, 'switch'])->name('lang.switch');

Route::middleware(['auth', 'project:form'])->group(function () {
    Route::get('/form', [FormController::class, 'index'])->name('form.index');
    Route::post('/form', [FormController::class, 'submit'])->name('form.submit');
    Route::get('/form/runs', [FormController::class, 'runs'])->name('form.runs.index');
    Route::get('/form/runs/{run}', [FormController::class, 'showRun'])->name('form.runs.show');
    Route::delete('/form/runs/{run}', [FormController::class, 'destroyRun'])->name('form.runs.destroy');
    Route::post('/form/runs/{run}/pause', [FormController::class, 'pauseRun'])->name('form.runs.pause');
    Route::post('/form/runs/{run}/continue', [FormController::class, 'continueRun'])->name('form.runs.continue');
    Route::post('/form/full-ai/auth-status', [FormController::class, 'checkFullAiAuth'])->name('form.full-ai.auth-status');
    Route::post('/form/full-ai/login', [FormController::class, 'loginFullAi'])->name('form.full-ai.login');
    Route::post('/form/full-ai/chat', [FormController::class, 'chatFullAutomation'])->name('form.full-ai.chat');
    Route::post('/form/full-ai/execute-step', [FormController::class, 'executeFullAiStep'])->name('form.full-ai.execute-step');
    Route::post('/form/full-ai/start', [FormController::class, 'startFullAutomation'])->name('form.full-ai.start');
    Route::post('/form/full-ai/{session}/approve', [FormController::class, 'approveFullAutomation'])->name('form.full-ai.approve');
    Route::post('/form/menu-agent/chat', [\App\Http\Controllers\FormMenuAgentController::class, 'chat'])->name('form.menu-agent.chat');
    Route::post('/api/upload-image', [FormController::class, 'uploadImage'])->name('api.upload-image');
});
