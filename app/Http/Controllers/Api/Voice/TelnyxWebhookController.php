<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\Voice;

use App\Http\Controllers\Controller;
use App\Services\Voice\Telnyx\TelnyxWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TelnyxWebhookController extends Controller
{
    public function handle(Request $request, TelnyxWebhookService $webhookService): JsonResponse
    {
        if (! $this->shouldAcceptWebhook($request)) {
            Log::warning('Telnyx webhook rejected: verification required but public key missing.');

            return response()->json([
                'success' => false,
                'message' => 'Webhook verification is not configured.',
            ], 403);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->all();

        $result = $webhookService->handle($payload);

        return response()->json([
            'success' => true,
            'duplicate' => $result['duplicate'],
            'handled' => $result['handled'],
            'event_type' => $result['event_type'],
        ]);
    }

    protected function shouldAcceptWebhook(Request $request): bool
    {
        $verify = (bool) config('voice.providers.telnyx.webhook_verify', true);
        $publicKey = config('voice.providers.telnyx.public_key');

        if (! $verify) {
            return true;
        }

        if (! blank($publicKey)) {
            // Signature verification will be implemented when Telnyx is activated.
            return true;
        }

        if (app()->environment(['local', 'testing'])) {
            return (bool) config('voice.providers.telnyx.webhook_verify_bypass', false);
        }

        return false;
    }
}
