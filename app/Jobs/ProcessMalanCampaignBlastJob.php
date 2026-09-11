<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Malan\MalanCampaign;
use App\Services\Malan\Campaigns\MalanCampaignService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessMalanCampaignBlastJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(
        public int $campaignId,
        public int $batchSize = 20,
    ) {}

    public function handle(MalanCampaignService $campaignService): void
    {
        $campaign = MalanCampaign::query()->find($this->campaignId);
        if ($campaign === null || ! $campaign->isRunning()) {
            return;
        }

        $more = $campaignService->processPendingBatch($campaign, $this->batchSize);
        if ($more && $campaign->fresh()?->isRunning()) {
            self::dispatch($campaign->id, $this->batchSize)->delay(now()->addSeconds(2));
        }
    }
}
