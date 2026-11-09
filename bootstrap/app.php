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
                ->group(base_path('routes/app-development.php'));
            Route::middleware('web')
                ->group(base_path('routes/kaman-support.php'));
            Route::middleware('web')
                ->group(base_path('routes/finance.php'));
            Route::middleware('web')
                ->group(base_path('routes/sales.php'));
            Route::middleware('web')
                ->group(base_path('routes/mobile.php'));
            Route::middleware('web')
                ->group(base_path('routes/officevision.php'));
            Route::middleware('api')
                ->prefix('api/officevision')
                ->group(base_path('routes/officevision-api.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->trustProxies(at: '*');

        $middleware->web(replace: [
            \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class => \App\Http\Middleware\ValidateCsrfToken::class,
        ]);

        $middleware->web(append: [
            \App\Http\Middleware\EnsureAccountIsOn::class,
            \App\Http\Middleware\SetLocale::class,
            \App\Http\Middleware\EnsureDoorIsOpen::class,
        ]);

        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'door' => \App\Http\Middleware\EnsureDoorIsOpen::class,
            'user-manager' => \App\Http\Middleware\EnsureUserManagesUsers::class,
            'project' => \App\Http\Middleware\EnsureUserCanAccessProject::class,
            'kaman-support.key' => \App\Http\Middleware\KamanSupport\AuthenticateSupportKey::class,
            'pos-live-scope' => \App\Http\Middleware\KamanSupport\LimitPosLiveLogViewer::class,
            'officevision.token' => \App\Http\Middleware\OfficeVision\AuthenticateOfficeVision::class,
        ]);

        $middleware->redirectGuestsTo(fn () => route('login'));

        $middleware->validateCsrfTokens(except: [
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
