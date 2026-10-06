<?php

declare(strict_types=1);

namespace App\Http\Middleware\KamanSupport;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateSupportKey
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $next($request);
        }

        $expected = (string) config('kaman-support.api_key');

        if ($expected === '') {
            return response()->json([
                'ok' => false,
                'error' => 'misconfigured',
                'message' => 'Kaman Support API key is not configured on the server.',
            ], 503);
        }

        $provided = (string) $request->header('X-Kaman-Support-Key', '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'ok' => false,
                'error' => 'unauthorized',
                'message' => 'Invalid or missing X-Kaman-Support-Key.',
            ], 401);
        }

        return $next($request);
    }
}
