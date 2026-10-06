<?php

use App\Support\ExpiredPageRecovery;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\HttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            Route::middleware('web')
                ->group(base_path('routes/ai-chatbot.php'));
            Route::middleware('web')
                ->group(base_path('routes/app-development.php'));
            Route::middleware('web')
                ->group(base_path('routes/kaman-support.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        $middleware->web(replace: [
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class => \App\Http\Middleware\ValidateCsrfToken::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\SetLocale::class,
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'project' => \App\Http\Middleware\EnsureUserCanAccessProject::class,
            'kaman-support.key' => \App\Http\Middleware\KamanSupport\AuthenticateSupportKey::class,
            'pos-live-scope' => \App\Http\Middleware\KamanSupport\LimitPosLiveLogViewer::class,
        ]);

        $middleware->redirectGuestsTo('/login');

        $middleware->validateCsrfTokens(except: [
            'api/voice/telnyx/webhook',
            'ai-chatbot/webhook/greenapi/*',
            'api/kaman-support/*',
        ]);
    })
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('kaman-support:prune-logs')->dailyAt('03:10')->timezone('Asia/Jerusalem');
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (HttpException $e, Request $request) {
            if ($e->getStatusCode() !== 419) {
                return null;
            }

            $response = app(ExpiredPageRecovery::class)->respond($request, rotateSession: false);

            if ($request->hasSession()) {
                $request->session()->save();
            }

            return $response;
        });
    })->create();
