<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards /ops/*. The X-Ops-Key header opens everything; a signed-in admin may only read.
 * Writes never rely on the session, so these routes can skip CSRF without being open to forged requests.
 */
class EnsureOpsAccess
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe() && ($request->user()?->is_admin ?? false)) {
            return $next($request);
        }

        $expected = (string) config('ops.key');

        if ($expected === '') {
            return response()->json([
                'success' => false,
                'error' => 'misconfigured',
                'message' => 'OPS_KEY is not configured on the server.',
            ], 503);
        }

        $provided = (string) $request->header('X-Ops-Key', '');

        if ($provided === '' || ! hash_equals($expected, $provided)) {
            return response()->json([
                'success' => false,
                'error' => 'unauthorized',
                'message' => 'Invalid or missing X-Ops-Key.',
            ], 401);
        }

        return $next($request);
    }
}
