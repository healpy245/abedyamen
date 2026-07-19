<?php

use App\Http\Controllers\ChatLoginController;
use App\Http\Controllers\FormController;
<<<<<<< HEAD
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\Admin\ChatbotSettingsController;
=======
>>>>>>> parent of cd712ea (First)
use App\Http\Controllers\LandingController;
use App\Http\Controllers\WhatsAppBotController;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Illuminate\Support\Facades\Route;

<<<<<<< HEAD
Route::get('/login', function () {
    return view('auth.login');
})->name('login');

Route::post('/login', function (Request $request) {
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

Route::post('/logout', function (Request $request) {
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

// GET https://kaman-workspace.com/ops/clear/483275634
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
        } catch (Throwable $e) {
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

// GET /ops/migrate-seed/9274618053?seeder=AiChatbotStudioSeeder
Route::get('/ops/migrate-seed/9274618053', function (Request $request) {
    $rawSeeder = trim((string) $request->query('seeder', ''));
    $seederClass = null;

    if ($rawSeeder !== '') {
        $seederClass = str_contains($rawSeeder, '\\')
            ? $rawSeeder
            : 'Database\\Seeders\\'.$rawSeeder;

        if (!class_exists($seederClass) || !is_subclass_of($seederClass, \Illuminate\Database\Seeder::class)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid seeder class.',
                'seeder' => $rawSeeder,
            ], 422);
        }
    }

    $results = [];

    try {
        $migrateExit = Artisan::call('migrate', ['--force' => true]);
        $results['migrate'] = [
            'ok' => $migrateExit === 0,
            'exit_code' => $migrateExit,
            'output' => trim(Artisan::output()),
        ];
    } catch (Throwable $e) {
        $results['migrate'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }

    try {
        $seedArgs = ['--force' => true];
        if ($seederClass !== null) {
            $seedArgs['--class'] = $seederClass;
        }

        $seedExit = Artisan::call('db:seed', $seedArgs);
        $results['seed'] = [
            'ok' => $seedExit === 0,
            'exit_code' => $seedExit,
            'class' => $seederClass,
            'output' => trim(Artisan::output()),
        ];
    } catch (Throwable $e) {
        $results['seed'] = [
            'ok' => false,
            'class' => $seederClass,
            'error' => $e->getMessage(),
        ];
    }

    $allOk = collect($results)->every(fn (array $r) => ($r['ok'] ?? false) === true);

    return response()->json([
        'success' => $allOk,
        'message' => $allOk
            ? 'Migrations and seeding completed.'
            : 'Migrate/seed failed. See results.',
        'results' => $results,
        'timestamp' => now()->toIso8601String(),
    ], $allOk ? 200 : 500);
})
    ->withoutMiddleware([
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
    ])
    ->name('ops.migrate-seed');

// GET /ops/refresh/28814
Route::get('/ops/refresh/28814', function () {
    $results = [];

    try {
        $refreshExit = Artisan::call('migrate:fresh', [
            '--seed' => true,
            '--force' => true,
        ]);

        $results['migrate:fresh --seed'] = [
            'ok' => $refreshExit === 0,
            'exit_code' => $refreshExit,
            'output' => trim(Artisan::output()),
        ];
    } catch (Throwable $e) {
        $results['migrate:fresh --seed'] = [
            'ok' => false,
            'error' => $e->getMessage(),
        ];
    }

    $clearCommands = [
        'optimize:clear',
        'config:clear',
        'cache:clear',
        'route:clear',
        'view:clear',
        'event:clear',
    ];

    foreach ($clearCommands as $command) {
        try {
            $exitCode = Artisan::call($command);
            $results[$command] = [
                'ok' => $exitCode === 0,
                'exit_code' => $exitCode,
                'output' => trim(Artisan::output()),
            ];
        } catch (Throwable $e) {
            $results[$command] = [
                'ok' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    $allOk = collect($results)->every(fn (array $r) => ($r['ok'] ?? false) === true);

    return response()->json([
        'success' => $allOk,
        'message' => $allOk
            ? 'Database refreshed, seeded, and clears completed.'
            : 'Refresh/seed/clear failed. See results.',
        'results' => $results,
        'timestamp' => now()->toIso8601String(),
    ], $allOk ? 200 : 500);
})
    ->withoutMiddleware([
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
    ])
    ->name('ops.refresh-seed-clear');

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
| Everything below this block sits behind the workspace login. Only the
| marketing landing page and the Green API webhook stay open.
*/
=======
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
>>>>>>> parent of cd712ea (First)

Route::get('/landing', [LandingController::class, 'show'])->name('landing.show');
Route::post('/landing', [LandingController::class, 'submit'])->name('landing.submit');

// Locale switch is used by the login screen and the public landing page, so it
// stays open. It only writes a locale to the session.
Route::get('/lang/{locale}', [LocaleController::class, 'switch'])->name('lang.switch');

<<<<<<< HEAD
// Green API posts here server-to-server: no session, no CSRF, no login.
=======
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
>>>>>>> parent of cd712ea (First)
Route::post('/whatsapp-bot/webhook', [WhatsAppBotController::class, 'webhook'])
    ->withoutMiddleware([
        ValidateCsrfToken::class,
        VerifyCsrfToken::class,
        StartSession::class,
        ShareErrorsFromSession::class,
    ])
    ->name('whatsapp.bot.webhook');
<<<<<<< HEAD

/*
|--------------------------------------------------------------------------
| Project: Restaurant Form
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'project:form'])->group(function () {
    Route::get('/form', [FormController::class, 'index'])->name('form.index');
    Route::post('/form', [FormController::class, 'submit'])->name('form.submit');
    Route::post('/form/full-ai/auth-status', [FormController::class, 'checkFullAiAuth'])->name('form.full-ai.auth-status');
    Route::post('/form/full-ai/login', [FormController::class, 'loginFullAi'])->name('form.full-ai.login');
    Route::post('/form/full-ai/chat', [FormController::class, 'chatFullAutomation'])->name('form.full-ai.chat');
    Route::post('/form/full-ai/execute-step', [FormController::class, 'executeFullAiStep'])->name('form.full-ai.execute-step');
    Route::post('/form/full-ai/start', [FormController::class, 'startFullAutomation'])->name('form.full-ai.start');
    Route::post('/form/full-ai/{session}/approve', [FormController::class, 'approveFullAutomation'])->name('form.full-ai.approve');

    // Image upload is only ever driven by the form UI.
    Route::post('/api/upload-image', [FormController::class, 'uploadImage'])->name('api.upload-image');
});

/*
|--------------------------------------------------------------------------
| Project: WhatsApp Bot
|--------------------------------------------------------------------------
*/

Route::middleware(['auth', 'project:whatsapp-bot'])->group(function () {
    Route::get('/whatsapp-bot', [WhatsAppBotController::class, 'index'])->name('whatsapp.bot.index');
    Route::get('/whatsapp-bot/events', [WhatsAppBotController::class, 'events'])->name('whatsapp.bot.events');
    Route::post('/whatsapp-bot/prompt/save', [WhatsAppBotController::class, 'savePrompt'])->name('whatsapp.bot.prompt.save');
    Route::post('/whatsapp-bot/prompt/reset', [WhatsAppBotController::class, 'resetPrompt'])->name('whatsapp.bot.prompt.reset');
    Route::post('/whatsapp-bot/toggle', [WhatsAppBotController::class, 'toggleWebhook'])->name('whatsapp.bot.toggle');
    Route::post('/whatsapp-bot/events/clear', [WhatsAppBotController::class, 'clearEvents'])->name('whatsapp.bot.events.clear');
    Route::post('/whatsapp-bot/test-send', [WhatsAppBotController::class, 'testSend'])->name('whatsapp.bot.test-send');
    Route::post('/whatsapp-bot/test-chat', [WhatsAppBotController::class, 'testChat'])->name('whatsapp.bot.test-chat');
    Route::post('/whatsapp-bot/test-chat/reset', [WhatsAppBotController::class, 'resetTestChat'])->name('whatsapp.bot.test-chat.reset');
    Route::post('/whatsapp-bot/test-staff-chat', [WhatsAppBotController::class, 'testStaffChat'])->name('whatsapp.bot.test-staff-chat');
    Route::post('/whatsapp-bot/test-staff-chat/reset', [WhatsAppBotController::class, 'resetTestStaffChat'])->name('whatsapp.bot.test-staff-chat.reset');
});

/*
|--------------------------------------------------------------------------
| Project: AI Chatbot Studio (legacy single-instance chatbot)
|--------------------------------------------------------------------------
| The multi-instance studio lives in routes/ai-chatbot.php.
*/

Route::middleware(['auth', 'project:ai-chatbot'])->group(function () {
    Route::get('/chatbot', [ChatbotController::class, 'index'])->name('chatbot.index');
    Route::post('/chatbot/conversations', [ChatbotController::class, 'storeConversation'])->name('chatbot.conversations.store');
    Route::get('/chatbot/conversations/{conversation}', [ChatbotController::class, 'showConversation'])->name('chatbot.conversations.show');
    Route::post('/chatbot/send', [ChatbotController::class, 'sendMessage'])->name('chatbot.send');
    Route::delete('/chatbot/conversations/{conversation}', [ChatbotController::class, 'destroyConversation'])->name('chatbot.conversations.destroy');
});

Route::middleware(['auth', 'admin'])->group(function () {
    Route::get('/admin/chatbot/settings', [ChatbotSettingsController::class, 'index'])->name('admin.chatbot.settings.index');
    Route::put('/admin/chatbot/settings', [ChatbotSettingsController::class, 'update'])->name('admin.chatbot.settings.update');
});
=======
>>>>>>> parent of cd712ea (First)
