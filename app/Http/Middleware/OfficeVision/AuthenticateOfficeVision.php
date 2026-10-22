<?php

declare(strict_types=1);

namespace App\Http\Middleware\OfficeVision;

use App\Models\OfficeVision\Device;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateOfficeVision
{
    public function handle(Request $request, Closure $next): Response
    {
        $plain = (string) $request->bearerToken();
        $device = $plain === ''
            ? null
            : Device::query()->where('token_hash', hash('sha256', $plain))->first();

        if ($device === null) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        $device->forceFill(['last_seen_at' => now()])->save();
        $request->attributes->set('officevision_device', $device);

        return $next($request);
    }
}
