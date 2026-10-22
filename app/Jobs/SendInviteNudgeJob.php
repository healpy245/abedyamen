<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Services\AiChatbot\MansourBotService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ten minutes after an invitation, remind her once if she has not replied.
 */
class SendInviteNudgeJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public function __construct(
        public int $conversationId,
        public int $contactId,
    ) {}

    public function handle(MansourBotService $bots): void
    {
        $bots->sendInviteNudge($this->conversationId, $this->contactId);
    }
}
