<?php

declare(strict_types=1);

namespace App\Http\Middleware\KamanSupport;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LimitSupportPayload
{
    public function handle(Request $request, Closure $next): Response
    {
        $max = (int) config('kaman-support.max_body_bytes', 262144);
        $declared = (int) $request->header('Content-Length', '0');

        if ($declared > $max) {
            return $this->tooLarge($max);
        }

        if (strlen($request->getContent()) > $max) {
            return $this->tooLarge($max);
        }

        return $next($request);
    }

    private function tooLarge(int $max): Response
    {
        return response()->json([
            'ok' => false,
            'error' => 'payload_too_large',
            'message' => 'Request body exceeds the '.$max.' byte limit.',
        ], 413);
    }
}
