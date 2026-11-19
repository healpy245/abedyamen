<?php

declare(strict_types=1);

namespace App\Services\Push;

use App\Enums\Door;
use App\Models\KamanSupport\SupportDevice;
use App\Models\KamanSupport\SupportTicket;
use App\Models\User;
use App\Models\WorkspacePushDevice;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Rings the Kaman Workspace phones when a restaurant sends a support ticket or
 * a POS uploads errors. Each phone hears only what its owner can open and what
 * the phone's settings left on. Sending happens after the response, so the
 * POS never waits on Firebase.
 */
class SupportPushNotifier
{
    public function __construct(
        private readonly FcmSender $fcm,
    ) {}

    public function ticketSubmitted(SupportTicket $ticket): void
    {
        if (! $this->fcm->enabled()) {
            return;
        }

        $ticketId = $ticket->getKey();
        $this->afterResponse(function () use ($ticketId): void {
            $ticket = SupportTicket::query()->find($ticketId);
            if ($ticket !== null) {
                $this->sendTicket($ticket);
            }
        });
    }

    /**
     * @param  list<string>  $messages  The new error messages in this upload.
     */
    public function failuresLogged(SupportDevice $device, array $messages): void
    {
        if ($messages === [] || ! $this->fcm->enabled()) {
            return;
        }

        $restaurant = trim((string) $device->restaurant);
        $minutes = max(1, (int) config('services.fcm.failure_cooldown_minutes', 10));
        // One push per restaurant per cooldown: the board has the full list.
        if (! Cache::add('push:failures:'.($restaurant !== '' ? $restaurant : 'device-'.$device->id), true, $minutes * 60)) {
            return;
        }

        $deviceId = $device->getKey();
        $this->afterResponse(function () use ($deviceId, $messages): void {
            $device = SupportDevice::query()->find($deviceId);
            if ($device !== null) {
                $this->sendFailures($device, $messages);
            }
        });
    }

    /**
     * Runs once the POS has its answer. The guard keeps it to one run even
     * where the app outlives a single request (tests, long-running workers).
     */
    private function afterResponse(callable $send): void
    {
        $done = false;
        app()->terminating(function () use ($send, &$done): void {
            if ($done) {
                return;
            }
            $done = true;
            self::safely($send);
        });
    }

    private function sendTicket(SupportTicket $ticket): void
    {
        $restaurant = $this->restaurantName($ticket->restaurant_name, $ticket->restaurant);
        $summary = Str::limit(trim((string) $ticket->summary), 160);

        foreach ($this->recipients('tickets', 'notify_tickets') as $phone) {
            $user = $phone->user;
            if (! $user instanceof User || ! $ticket->isVisibleTo($user)) {
                continue;
            }
            $locale = $this->locale($phone);
            $this->deliver(
                $phone,
                (string) __('kaman-support.push.ticket_title', ['restaurant' => $restaurant], $locale),
                $summary !== '' ? $summary : (string) __('kaman-support.push.ticket_body_empty', [], $locale),
                ['type' => 'ticket', 'ticket' => (string) $ticket->public_id, 'restaurant' => (string) $ticket->restaurant],
                'support_tickets',
                'ticket-'.$ticket->public_id,
            );
        }
    }

    /**
     * @param  list<string>  $messages
     */
    private function sendFailures(SupportDevice $device, array $messages): void
    {
        $restaurant = $this->restaurantName($device->restaurant_name, $device->restaurant);
        $first = Str::limit(trim((string) ($messages[0] ?? '')), 140);
        $count = count($messages);

        foreach ($this->recipients('logs', 'notify_failures') as $phone) {
            $user = $phone->user;
            if (! $user instanceof User || ! $device->isVisibleTo($user)) {
                continue;
            }
            $locale = $this->locale($phone);
            $this->deliver(
                $phone,
                (string) __('kaman-support.push.failures_title', ['restaurant' => $restaurant], $locale),
                (string) trans_choice('kaman-support.push.failures_body', $count, ['count' => $count, 'message' => $first], $locale),
                ['type' => 'failures', 'restaurant' => (string) $device->restaurant, 'device' => (string) $device->id],
                'support_failures',
                'failures-'.$device->restaurant,
            );
        }
    }

    /**
     * Phones whose owner can open this Support sub door and left this kind on.
     *
     * @return iterable<WorkspacePushDevice>
     */
    private function recipients(string $subDoor, string $switch): iterable
    {
        $phones = WorkspacePushDevice::query()
            ->with('user')
            ->where($switch, true)
            ->get();

        foreach ($phones as $phone) {
            $user = $phone->user;
            if (! $user instanceof User || $user->isTurnedOff() || ! $user->canOpenSubDoor(Door::Support, $subDoor)) {
                continue;
            }

            yield $phone;
        }
    }

    /**
     * @param  array<string, string>  $data
     */
    private function deliver(WorkspacePushDevice $phone, string $title, string $body, array $data, string $channel, string $tag): void
    {
        try {
            $result = $this->fcm->send($phone->token, $title, $body, $data, $channel, $tag);
        } catch (\Throwable $error) {
            report($error);

            return;
        }

        if ($result === FcmSender::RESULT_GONE) {
            // The app was uninstalled or the token rotated: stop sending to it.
            $phone->delete();

            return;
        }
        if ($result === FcmSender::RESULT_SENT) {
            $phone->forceFill(['last_sent_at' => now()])->saveQuietly();
        }
    }

    private function locale(WorkspacePushDevice $phone): string
    {
        return in_array($phone->locale, ['en', 'ar', 'he'], true) ? $phone->locale : 'en';
    }

    private function restaurantName(?string $name, ?string $slug): string
    {
        $name = trim((string) $name);
        if ($name !== '' && $name !== '_pending') {
            return $name;
        }
        $slug = trim((string) $slug);

        return $slug !== '' && $slug !== '_pending' ? $slug : 'Kaman';
    }

    /**
     * Never let a push problem stop a ticket or a log upload.
     */
    public static function safely(callable $send): void
    {
        try {
            $send();
        } catch (\Throwable $error) {
            Log::warning('push.notify_failed', ['error' => $error->getMessage()]);
        }
    }
}
