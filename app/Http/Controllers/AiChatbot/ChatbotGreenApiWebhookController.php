<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Services\AiChatbot\ChatbotGreenApiService;
use App\Services\AiChatbot\WhapiCloudClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotGreenApiWebhookController extends Controller
{
    public function __construct(
        protected ChatbotGreenApiService $greenApiService,
        protected WhapiCloudClient $whapi,
    ) {}

    public function handle(Request $request, string $token, ?string $line = null): JsonResponse
    {
        if (is_string($line) && $line !== '') {
            $request->merge(['_mansour_line' => $line]);
        }

        $instance = $this->greenApiService->findByWebhookToken($token);

        if ($instance === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown webhook token.',
            ], 404);
        }

        $result = $this->greenApiService->handleWebhook($instance, $request);
        $status = ($result['ok'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }

    public function handleShared(Request $request, string $token): JsonResponse
    {
        if (! $this->whapi->webhookTokenMatches($token)) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown webhook token.',
            ], 404);
        }

        $instance = $this->whapi->resolveInstance($request);
        if ($instance === null) {
            return response()->json([
                'ok' => true,
                'ignored' => true,
                'reason' => 'No chatbot instance is available for this Whapi channel.',
            ]);
        }

        $result = $this->greenApiService->handleWebhook($instance, $request);
        $status = ($result['ok'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }
}
