<?php

declare(strict_types=1);

namespace App\Http\Controllers\AiChatbot;

use App\Http\Controllers\Controller;
use App\Services\Malan\Campaigns\MalanCampaignService;
use App\Services\Malan\Campaigns\MalanCampaignWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class MalanCampaignWebhookController extends Controller
{
    public function __construct(
        protected MalanCampaignService $campaignService,
        protected MalanCampaignWebhookService $webhookService,
    ) {}

    public function handle(Request $request, string $token): JsonResponse
    {
        $campaign = $this->campaignService->findByWebhookToken($token);
        if ($campaign === null) {
            return response()->json([
                'ok' => false,
                'error' => 'Unknown campaign webhook token.',
            ], 404);
        }

        $result = $this->webhookService->handle($campaign, $request);
        $status = ($result['ok'] ?? false) ? 200 : 422;

        return response()->json($result, $status);
    }
}
