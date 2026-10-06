<?php

declare(strict_types=1);

namespace App\Http\Middleware\KamanSupport;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleSupportCors
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('OPTIONS')) {
            return $this->apply($request, response('', 204));
        }

        return $this->apply($request, $next($request));
    }

    private function apply(Request $request, Response $response): Response
    {
        $origin = (string) $request->headers->get('Origin', '');

        if ($origin !== '' && $this->originAllowed($origin)) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
            $response->headers->set('Vary', 'Origin');
            $response->headers->set('Access-Control-Allow-Credentials', 'false');
        }

        $response->headers->set('Access-Control-Allow-Methods', 'GET, POST, PATCH, OPTIONS');
        $response->headers->set(
            'Access-Control-Allow-Headers',
            'Content-Type, Accept, X-Kaman-Support-Key, X-Kaman-Restaurant, X-Kaman-Device-Id',
        );
        $response->headers->set('Access-Control-Max-Age', '86400');

        return $response;
    }

    private function originAllowed(string $origin): bool
    {
        /** @var list<string> $patterns */
        $patterns = config('kaman-support.cors_origin_patterns', []);

        foreach ($patterns as $pattern) {
            if (@preg_match($pattern, $origin) === 1) {
                return true;
            }
        }

        return false;
    }
}
