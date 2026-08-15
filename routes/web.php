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

Route::get('/landing', [LandingController::class, 'show'])->name('landing.show');
Route::post('/landing', [LandingController::class, 'submit'])->name('landing.submit');

Route::get('/lang/{locale}', [LocaleController::class, 'switch'])->name('lang.switch');

Route::middleware(['auth', 'project:form'])->group(function () {
    Route::get('/form', [FormController::class, 'index'])->name('form.index');
    Route::post('/form', [FormController::class, 'submit'])->name('form.submit');
    Route::post('/form/full-ai/auth-status', [FormController::class, 'checkFullAiAuth'])->name('form.full-ai.auth-status');
    Route::post('/form/full-ai/login', [FormController::class, 'loginFullAi'])->name('form.full-ai.login');
    Route::post('/form/full-ai/chat', [FormController::class, 'chatFullAutomation'])->name('form.full-ai.chat');
    Route::post('/form/full-ai/execute-step', [FormController::class, 'executeFullAiStep'])->name('form.full-ai.execute-step');
    Route::post('/form/full-ai/start', [FormController::class, 'startFullAutomation'])->name('form.full-ai.start');
    Route::post('/form/full-ai/{session}/approve', [FormController::class, 'approveFullAutomation'])->name('form.full-ai.approve');
    Route::post('/api/upload-image', [FormController::class, 'uploadImage'])->name('api.upload-image');
});
