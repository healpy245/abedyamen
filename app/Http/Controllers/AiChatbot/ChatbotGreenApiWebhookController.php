<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Services\AiChatbot\ChatbotGreenApiService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChatbotGreenApiWebhookController extends Controller
{
    public function __construct(
        protected ChatbotGreenApiService $greenApiService,
    ) {}

    public function handle(Request $request, string $token): JsonResponse
    {
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
}
