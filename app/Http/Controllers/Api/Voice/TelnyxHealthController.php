<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Voice;

use App\Http\Controllers\Controller;
use App\Services\Voice\Providers\TelnyxVoiceProvider;
use Illuminate\Http\JsonResponse;

class TelnyxHealthController extends Controller
{
    public function __invoke(TelnyxVoiceProvider $provider): JsonResponse
    {
        $missing = array_values(array_filter(
            $provider->missingConfigurationKeys(),
            static fn (string $key): bool => in_array($key, ['TELNYX_API_KEY', 'TELNYX_CONNECTION_ID', 'TELNYX_PHONE_NUMBER', 'TELNYX_PUBLIC_KEY'], true),
        ));

        return response()->json([
            'success' => true,
            'provider' => 'telnyx',
            'configured' => $missing === [],
            'webhook_ready' => true,
            'missing_configuration' => $missing,
        ]);
    }
}
