<?php

declare(strict_types=1);

namespace App\Http\Middleware\KamanSupport;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitSupportMedia
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('kaman-support.max_media_bytes', 50331648);
        $declared = (int) $request->header('Content-Length', '0');

        if ($declared > $max) {
            return response()->json([
                'ok' => false,
                'error' => 'payload_too_large',
                'message' => 'Recording upload exceeds the '.$max.' byte limit.',
            ], 413);
        }

        return $next($request);
    }
}
