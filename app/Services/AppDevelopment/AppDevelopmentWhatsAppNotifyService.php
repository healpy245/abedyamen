<?php

declare(strict_types=1);

namespace App\Services\AppDevelopment;

use App\Enums\AppDevelopmentTicketStatus;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Services\AiChatbot\ChatbotGreenApiService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AppDevelopmentWhatsAppNotifyService
{
    public function __construct(
        private readonly ChatbotGreenApiService $greenApi,
    ) {}

    /**
     * @param  list<int>|null  $userIds
     * @return array{sent: int, skipped: int, failed: int, count: int, recipients: list<string>}
     */
    public function notifyOpenTickets(?array $userIds = null): array
    {
        $count = AppDevelopmentTicket::query()
            ->where('status', AppDevelopmentTicketStatus::Open)
            ->count();

        $members = AppDevelopmentMember::selectableDevelopers()
            ->when(
                $userIds !== null,
                fn ($query) => $query->whereIn('user_id', $userIds === [] ? [0] : $userIds),
                fn ($query) => $query->where('whatsapp_notifications_enabled', true),
            )
            ->get();

        return $this->blast(
            $members,
            fn (AppDevelopmentMember $member): string => __('app-development.whatsapp.open_tickets', [
                'name' => $member->user?->name ?? '',
                'count' => $count,
            ]),
            $count,
        );
    }

    /**
     * @param  list<int>|null  $userIds
     * @return array{sent: int, skipped: int, failed: int, count: int, recipients: list<string>}
     */
    public function notifyQaTickets(?array $userIds = null): array
    {
        $count = AppDevelopmentTicket::query()
            ->where('status', AppDevelopmentTicketStatus::Qa)
            ->count();

        $members = AppDevelopmentMember::selectableTesters()
            ->when(
                $userIds !== null,
                fn ($query) => $query->whereIn('user_id', $userIds === [] ? [0] : $userIds),
                fn ($query) => $query->where('whatsapp_notifications_enabled', true),
            )
            ->get();

        return $this->blast(
            $members,
            fn (AppDevelopmentMember $member): string => __('app-development.whatsapp.qa_tickets', [
                'name' => $member->user?->name ?? '',
                'count' => $count,
            ]),
            $count,
        );
    }

    /**
     * Ensure Kaman Bot never auto-replies to App Development worker numbers.
     */
    public function syncWorkerPhonesOntoKamanBotIgnoreList(): void
    {
        $instance = $this->kamanWhatsappInstance();
        if ($instance === null) {
            return;
        }

        $settings = $instance->integration_settings ?? [];
        $manual = $instance->manualIgnoredReplyPhones();
        $api = $instance->apiIgnoredReplyPhones();
        $workers = AppDevelopmentMember::allWorkerPhones();

        $apiSeen = [];
        foreach ($api as $phone) {
            $normalized = $this->greenApi->normalizePhoneDigits((string) $phone);
            if ($normalized !== '') {
                $apiSeen[$normalized] = true;
            }
        }

        $merged = [];
        $seen = [];
        foreach (array_merge($manual, $workers) as $phone) {
            $normalized = $this->greenApi->normalizePhoneDigits((string) $phone);
            if ($normalized === '' || isset($seen[$normalized]) || isset($apiSeen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $merged[] = (string) $phone;
        }

        $settings['ignored_reply_phones'] = $merged;
        $instance->forceFill(['integration_settings' => $settings])->save();
    }

    public function kamanWhatsappInstance(): ?ChatbotInstance
    {
        return ChatbotInstance::query()
            ->where('integration_type', 'kaman_whatsapp')
            ->orderBy('id')
            ->first();
    }

    public function chatIdFromPhone(string $phone): string
    {
        $digits = $this->greenApi->normalizePhoneDigits($phone);
        if ($digits === '') {
            return '';
        }

        return $digits.'@c.us';
    }

    /**
     * @param  Collection<int, AppDevelopmentMember>  $members
     * @param  callable(AppDevelopmentMember): string  $messageFor
     * @return array{sent: int, skipped: int, failed: int, count: int, recipients: list<string>}
     */
    private function blast(Collection $members, callable $messageFor, int $count): array
    {
        $instance = $this->kamanWhatsappInstance();
        if ($instance === null) {
            throw new RuntimeException(__('app-development.errors.kaman_whatsapp_missing'));
        }

        $sendUrl = trim((string) ($instance->greenapi_url ?? ''));
        if ($sendUrl === '') {
            throw new RuntimeException(__('app-development.errors.kaman_whatsapp_unconfigured'));
        }

        $sent = 0;
        $skipped = 0;
        $failed = 0;
        $recipients = [];

        foreach ($members as $member) {
            $chatId = $this->chatIdFromPhone((string) $member->phone);
            if ($chatId === '') {
                $skipped++;
                continue;
            }

            $message = trim($messageFor($member));
            if ($message === '') {
                $skipped++;
                continue;
            }

            try {
                $result = $this->greenApi->sendMessage($sendUrl, $chatId, $message, 0);
                $status = (int) ($result['status'] ?? 0);
                if ($status >= 200 && $status < 300) {
                    $sent++;
                    $recipients[] = $member->user?->name ?? (string) $member->phone;
                } else {
                    $failed++;
                    Log::warning('App Development WhatsApp notify failed', [
                        'member_id' => $member->id,
                        'chat_id' => $chatId,
                        'status' => $status,
                        'body' => $result['body'] ?? null,
                    ]);
                }
            } catch (Throwable $e) {
                $failed++;
                Log::warning('App Development WhatsApp notify exception', [
                    'member_id' => $member->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return [
            'sent' => $sent,
            'skipped' => $skipped,
            'failed' => $failed,
            'count' => $count,
            'recipients' => $recipients,
        ];
    }
}
