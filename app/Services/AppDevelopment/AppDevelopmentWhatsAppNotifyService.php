<?php

declare(strict_types=1);

namespace App\Services\AppDevelopment;

use App\Enums\AppDevelopmentTicketStatus;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\KamanWhatsappLine;
use App\Services\WhatsApp\KamanWhatsApp;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class AppDevelopmentWhatsAppNotifyService
{
    public function __construct(
        private readonly KamanWhatsApp $whatsapp,
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

        $members = $userIds !== null
            ? AppDevelopmentMember::selectableTeam()->whereIn('user_id', $userIds === [] ? [0] : $userIds)->get()
            : AppDevelopmentMember::selectableDevelopers()->where('whatsapp_notifications_enabled', true)->get();

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

        $members = $userIds !== null
            ? AppDevelopmentMember::selectableTeam()->whereIn('user_id', $userIds === [] ? [0] : $userIds)->get()
            : AppDevelopmentMember::selectableTesters()->where('whatsapp_notifications_enabled', true)->get();

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
     * Keep worker numbers on the Kaman line's ignore list so a bot on that line does not answer them.
     */
    public function syncWorkerPhonesOntoKamanBotIgnoreList(): void
    {
        $line = $this->whatsapp->line();
        if ($line === null) {
            return;
        }

        $settings = is_array($line->integration_settings) ? $line->integration_settings : [];
        $manual = $line->manualIgnoredReplyPhones();
        $api = $line->apiIgnoredReplyPhones();
        $workers = AppDevelopmentMember::allWorkerPhones();

        $apiSeen = [];
        foreach ($api as $phone) {
            $normalized = $this->whatsapp->normalizePhoneDigits((string) $phone);
            if ($normalized !== '') {
                $apiSeen[$normalized] = true;
            }
        }

        $merged = [];
        $seen = [];
        foreach (array_merge($manual, $workers) as $phone) {
            $normalized = $this->whatsapp->normalizePhoneDigits((string) $phone);
            if ($normalized === '' || isset($seen[$normalized]) || isset($apiSeen[$normalized])) {
                continue;
            }
            $seen[$normalized] = true;
            $merged[] = (string) $phone;
        }

        $settings['ignored_reply_phones'] = $merged;
        $line->forceFill(['integration_settings' => $settings])->save();
    }

    public function kamanWhatsappInstance(): ?KamanWhatsappLine
    {
        return $this->whatsapp->line();
    }

    public function chatIdFromPhone(string $phone): string
    {
        return $this->whatsapp->chatIdFromPhone($phone);
    }

    /**
     * @param  Collection<int, AppDevelopmentMember>  $members
     * @param  callable(AppDevelopmentMember): string  $messageFor
     * @return array{sent: int, skipped: int, failed: int, count: int, recipients: list<string>}
     */
    private function blast(Collection $members, callable $messageFor, int $count): array
    {
        if ($this->whatsapp->line() === null) {
            throw new RuntimeException(__('app-development.errors.kaman_whatsapp_missing'));
        }

        $sendUrl = $this->whatsapp->sendUrl();
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
                $result = $this->whatsapp->sendMessage($sendUrl, $chatId, $message);
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
