<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Prevent PHP/request timeouts during large App Development video uploads.
 */
class ExtendUploadTimeout
{
    public function handle(Request $request, Closure $next): Response
    {
        @ignore_user_abort(true);
        @set_time_limit(0);
        @ini_set('max_execution_time', '0');
        @ini_set('max_input_time', '0');

        return $next($request);
    }
}
