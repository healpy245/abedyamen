<?php

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

if (!function_exists('formApiExpectsJson')) {
    /**
     * Whether a form route should receive a JSON error body instead of HTML.
     */
    function formApiExpectsJson(Request $request): bool
    {
        if (!$request->is('form') && !$request->is('form/*')) {
            return false;
        }

        return $request->expectsJson()
            || $request->ajax()
            || $request->header('X-Live-Debug') === '1'
            || str_contains((string) $request->header('Accept', ''), 'application/json');
    }
}

if (!function_exists('isSessionStorageException')) {
    function isSessionStorageException(QueryException $e): bool
    {
        $sql = $e->getSql();

        return str_contains($sql, '`sessions`')
            || str_contains($sql, '"sessions"')
            || str_contains($e->getMessage(), 'sessions');
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: [
            __DIR__.'/../routes/web.php',
            __DIR__.'/../routes/ai-chatbot.php',
        ],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'admin' => \App\Http\Middleware\EnsureUserIsAdmin::class,
            'ai-chatbot.auth' => \App\Http\Middleware\AiChatbotAuthenticate::class,
        ]);

        $middleware->redirectGuestsTo('/');

        $middleware->validateCsrfTokens(except: [
            'whatsapp-bot/webhook',
            'whatsapp-bot/webhook/*',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->render(function (Throwable $e, Request $request) {
            if (!formApiExpectsJson($request)) {
                return null;
            }

            if ($e instanceof ValidationException) {
                $message = $e->validator->errors()->first() ?: 'Validation failed.';

                return response()->json([
                    'success' => false,
                    'message' => $message,
                    'error' => $message,
                    'errors' => $e->errors(),
                ], $e->status);
            }

            if ($e instanceof TokenMismatchException) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session expired or invalid CSRF token. Refresh the page and try again.',
                    'error' => 'Session expired or invalid CSRF token. Refresh the page and try again.',
                ], 419);
            }

            if ($e instanceof QueryException && isSessionStorageException($e)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session storage is unavailable. Check database configuration or set SESSION_DRIVER=file in .env.',
                    'error' => 'Session storage is unavailable. Check database configuration or set SESSION_DRIVER=file in .env.',
                ], 503);
            }

            if ($e instanceof HttpExceptionInterface && $e->getStatusCode() === 419) {
                return response()->json([
                    'success' => false,
                    'message' => 'Session expired or invalid CSRF token. Refresh the page and try again.',
                    'error' => 'Session expired or invalid CSRF token. Refresh the page and try again.',
                ], 419);
            }

            $message = config('app.debug')
                ? $e->getMessage()
                : 'An unexpected server error occurred. Please try again.';

            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;

            return response()->json([
                'success' => false,
                'message' => $message,
                'error' => $message,
            ], $status >= 400 && $status < 600 ? $status : 500);
        });
    })->create();
