<?php

use App\Actions\SignIn;
use App\Http\Controllers\FormController;
use App\Http\Controllers\LandingController;
use App\Http\Controllers\LocaleController;
use App\Http\Middleware\EnsureOpsAccess;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;

Route::get('/login', function () {
    $user = auth()->user();
    if ($user !== null && $user->afterLoginUrl() !== route('login')) {
        return redirect($user->afterLoginUrl());
    }

    return view('auth.login');
})->name('login');

Route::post('/login', function (Illuminate\Http\Request $request) {
    return app(SignIn::class)->attempt($request, gate: 'kaman');
});

Route::get('/csrf-token', function () {
    return response()->json(['token' => csrf_token()]);
})->name('csrf.token');

Route::post('/logout', function (Illuminate\Http\Request $request) {
    Auth::logout();

    $request->session()->invalidate();
    $request->session()->regenerateToken();

    return redirect()->route('login');
})->name('logout');

Route::get('/privacy', function () {
    return view('privacy', ['focus' => 'policy']);
})->name('privacy');

Route::get('/privacy-choices', function () {
    return view('privacy', ['focus' => 'choices']);
})->name('privacy.choices');

Route::get('/cart/{code}', function (string $code) {
    if (! preg_match('/^[a-z0-9]{6,32}$/', $code)) {
        abort(404);
    }

    $intent = 'intent://cart/'.$code.'#Intent;scheme=kaman;package=com.kaman.user;end';
    $safe = e($code);
    $open = e($intent);
    $openJson = json_encode($intent, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
    $html = <<<HTML
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Kaman</title>
</head>
<body style="margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#161412;color:#F5EAD8;font-family:sans-serif">
<div style="text-align:center;padding:24px;max-width:360px">
<h1 style="font-size:22px;margin:0 0 8px">Kaman</h1>
<p style="margin:0 0 8px">Open this shared cart in the app.</p>
<p style="letter-spacing:2px;font-weight:700;margin:0 0 18px">{$safe}</p>
<a href="{$open}" style="display:inline-block;background:#C67139;color:#fff;text-decoration:none;padding:14px 22px;border-radius:999px;font-weight:700">Open in Kaman</a>
</div>
<script>setTimeout(function(){location.href={$openJson};},300);</script>
</body>
</html>
HTML;

    return response($html, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
})->where('code', '[a-z0-9]{6,32}')->name('kaman-cart.open');

Route::get('/', function () {
    return redirect()->route('login');
})->name('home');

Route::middleware('auth')->group(function () {
    Route::get('/today', \App\Http\Controllers\TodayController::class)->name('today');
    Route::middleware('user-manager')->group(function () {
        Route::get('/workspace/users', [\App\Http\Controllers\UserAccessController::class, 'index'])->name('workspace.users');
        Route::put('/workspace/users/{user}/access', [\App\Http\Controllers\UserAccessController::class, 'update'])->name('workspace.users.access');
        Route::put('/workspace/users/{user}/account', [\App\Http\Controllers\UserAccessController::class, 'account'])->name('workspace.users.account');
        Route::post('/workspace/users', [\App\Http\Controllers\UserAccessController::class, 'store'])->name('workspace.users.store');
        Route::put('/workspace/users/{user}/password', [\App\Http\Controllers\UserAccessController::class, 'password'])->name('workspace.users.password');
    });
    Route::get('/design', [\App\Http\Controllers\DesignConceptController::class, 'index'])->name('design.index');
    Route::get('/design/modern-light', [\App\Http\Controllers\DesignConceptController::class, 'modernLight'])->name('design.modern-light');
    // The Finance sections of the restaurant modal, read-only, for the doors other than Finance.
    Route::get('/workspace/restaurants/finance/{client}', [\App\Http\Controllers\Finance\ClientController::class, 'preview'])
        ->name('workspace.restaurant.finance');
    Route::get('/workspace/overview/{page?}', [\App\Http\Controllers\DesignConceptController::class, 'overview'])
        ->where('page', 'dashboard|restaurants')
        ->name('workspace.overview');
    Route::get('/workspace/agents/{page?}', [\App\Http\Controllers\AgentsWorkspaceController::class, 'show'])
        ->where('page', 'leads|calendar|clients')
        ->name('workspace.agents');
    Route::get('/design/{concept}', [\App\Http\Controllers\DesignConceptController::class, 'show'])
        ->whereIn('concept', \App\Http\Controllers\DesignConceptController::CONCEPTS)
        ->name('design.show');

    Route::get('/downloads/kaman-workspace', function () {
        $path = (string) config('kaman-workspace.apk_path');
        abort_unless(is_file($path), 404);

        return response()->download($path, (string) config('kaman-workspace.download_name'), [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    })->name('downloads.kaman-workspace');

    Route::get('/downloads/kaman-support', function () {
        $path = (string) config('kaman-support.apk_path');
        abort_unless(is_file($path), 404);

        return response()->download($path, 'KamanSupport-1.0.11.apk', [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    })->name('downloads.kaman-support');

    Route::get('/downloads/kaman-app', function () {
        $user = auth()->user();
        abort_unless(
            $user !== null && strcasecmp((string) $user->email, (string) config('kaman-app.allowed_email')) === 0,
            403,
        );

        $path = (string) config('kaman-app.apk_path');
        abort_unless(is_file($path), 404);

        return response()->download($path, (string) config('kaman-app.download_name'), [
            'Content-Type' => 'application/vnd.android.package-archive',
        ]);
    })->name('downloads.kaman-app');
});

Route::post('/ops/clear', function () {
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
    ->middleware(EnsureOpsAccess::class)
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
    ->name('ops.clear');

Route::post('/ops/migrate', function () {
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
    ->middleware(EnsureOpsAccess::class)
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
    ->name('ops.migrate');

Route::post('/ops/seed', function () {
    try {
        $results = [];

        foreach ([
            \Database\Seeders\WorkspaceUserSeeder::class,
            \Database\Seeders\AppDevelopmentMemberSeeder::class,
            \Database\Seeders\KamanSupportWorkerSeeder::class,
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
    ->middleware(EnsureOpsAccess::class)
    ->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class)
    ->name('ops.seed');

// Reading is a GET; the options that change something are POST only.
// Reading is a GET; the options that change something or send WhatsApp are POST only.
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
    Route::get('/form/full-ai/restaurants', [FormController::class, 'restaurantTokens'])->name('form.full-ai.restaurants');
    Route::delete('/form/full-ai/restaurants', [FormController::class, 'forgetRestaurantToken'])->name('form.full-ai.restaurants.forget');
    Route::post('/form/full-ai/chat', [FormController::class, 'chatFullAutomation'])->name('form.full-ai.chat');
    Route::post('/form/full-ai/execute-step', [FormController::class, 'executeFullAiStep'])->name('form.full-ai.execute-step');
    Route::post('/form/full-ai/start', [FormController::class, 'startFullAutomation'])->name('form.full-ai.start');
    Route::post('/form/full-ai/{session}/approve', [FormController::class, 'approveFullAutomation'])->name('form.full-ai.approve');
    Route::post('/form/menu-agent/chat', [\App\Http\Controllers\FormMenuAgentController::class, 'chat'])->name('form.menu-agent.chat');
    Route::post('/form/menu-agent/transcribe', [\App\Http\Controllers\FormMenuAgentController::class, 'transcribe'])->name('form.menu-agent.transcribe');
    Route::post('/api/upload-image', [FormController::class, 'uploadImage'])->name('api.upload-image');
});
