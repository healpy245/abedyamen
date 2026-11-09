<?php

declare(strict_types=1);

namespace App\Services\KamanSupport;

use App\Enums\Door;
use App\Enums\KamanSupport\SupportTicketStatus;
use App\Http\Controllers\AgentsWorkspaceController;
use App\Models\KamanSupport\SupportDevice;
use App\Models\KamanSupport\SupportTicket;
use App\Models\User;

/**
 * What the restaurant modal shows on its Support section: the restaurant's tickets and POS devices.
 * Keyed by kaman.rest company id (matched on subdomain); a restaurant that is not a kaman.rest company
 * gets its own id, and comes with a stand-in restaurant row so the modal can still open it.
 * Bound per request, so the pages that ask for it more than once read the tables once.
 */
class RestaurantSupport
{
    private const TICKETS_PER_RESTAURANT = 20;

    /** @var array{support: array<string, array<string, mixed>>, extra: list<array<string, mixed>>, keys: array<string, string>}|null */
    private ?array $built = null;

    /**
     * @return array<string, array{tickets: list<array<string, mixed>>, devices: list<array<string, mixed>>, openTickets: int}>
     */
    public function byCompany(?User $user): array
    {
        return $this->build($user)['support'];
    }

    /**
     * Everything the restaurant modal needs for its Support section. People who can open the Support door
     * get the same view as its board (overview, devices, recent logs, tickets) loaded live from it.
     *
     * @return array<string, mixed>
     */
    public function boot(?User $user): array
    {
        $visible = $user?->canSeeRestaurantSection(Door::Support) ?? false;
        $live = $visible && $user->canOpenSubDoor(Door::Support, 'live');
        $logs = $visible && $user->canOpenSubDoor(Door::Support, 'logs');
        $tickets = $visible && $user->canOpenSubDoor(Door::Support, 'tickets');

        return [
            'support' => (object) $this->byCompany($user),
            'locked' => ! $visible,
            // These tabs of the restaurant modal belong to the Overview door.
            'lockedTabs' => $user?->canOpenDoor(Door::Overview) ? [] : ['overview', 'features', 'activity'],
            'card' => $live ? route('kaman-support.restaurant.card', ['restaurant' => '__SLUG__']) : null,
            'feed' => $live ? route('kaman-support.live.feed') : null,
            'logs' => $logs ? route('kaman-support.logs') : null,
            'board' => $live ? route('kaman-support.index') : null,
            // A ticket opens in the ticket modal, loaded from here.
            'ticket' => $tickets ? route('kaman-support.show', ['ticket' => '__ID__']) : null,
            'ticketScript' => $tickets ? asset('js/ticket-view.js').'?v='.@filemtime(public_path('js/ticket-view.js')) : null,
            'labels' => [
                'locked' => __('access.section_locked'),
                'devices' => __('kaman-support.ws_devices'),
                'recent' => __('kaman-support.ws_recent'),
                'tickets' => __('kaman-support.nav_tickets'),
                'devicesOnline' => __('kaman-support.ws_device_count', ['online' => ':online', 'total' => ':total']),
                'last' => __('kaman-support.ws_last'),
                'never' => __('kaman-support.never_seen'),
                'viewAllLogs' => __('kaman-support.view_all_logs'),
                'empty' => __('kaman-support.ws_empty_logs'),
                'healthy' => __('kaman-support.ws_healthy'),
                'warning' => __('kaman-support.ws_warning'),
                'offline' => __('kaman-support.ws_offline'),
                'unknown' => __('kaman-support.ws_unknown'),
                'online' => __('kaman-support.ws_online'),
                'mall' => __('kaman-support.ws_cashier_mall'),
                'lite' => __('kaman-support.ws_lite'),
                'openBoard' => __('kaman-support.board_title'),
                'olderDevices' => __('kaman-support.ws_older_devices', ['count' => ':count']),
                'hideOlder' => __('kaman-support.ws_hide_older'),
                'noRecentDevices' => __('kaman-support.ws_no_recent_devices'),
                'failuresNote' => __('kaman-support.ws_failures_note'),
                'noFailures' => __('kaman-support.ws_no_failures'),
            ],
        ];
    }

    /**
     * Stand-in rows for restaurants that only Support knows.
     *
     * @return list<array<string, mixed>>
     */
    public function extraRestaurants(?User $user): array
    {
        return $this->build($user)['extra'];
    }

    /**
     * The restaurant id a Support restaurant slug opens in the modal.
     */
    public function keyFor(?User $user, ?string $slug): string
    {
        $slug = mb_strtolower(trim((string) $slug));

        return $slug === '' ? '' : ($this->build($user)['keys'][$slug] ?? 'sup-'.$slug);
    }

    /**
     * @return array{support: array<string, array<string, mixed>>, extra: list<array<string, mixed>>, keys: array<string, string>}
     */
    private function build(?User $user): array
    {
        if ($this->built !== null) {
            return $this->built;
        }

        if (! ($user?->canSeeRestaurantSection(Door::Support) ?? false)) {
            return $this->built = ['support' => [], 'extra' => [], 'keys' => []];
        }

        $companies = app(AgentsWorkspaceController::class)->catalog()['companies'];
        $keys = [];
        foreach ($companies as $company) {
            $slug = mb_strtolower(trim((string) ($company['subdomain'] ?? '')));
            if ($slug !== '') {
                $keys[$slug] = (string) $company['id'];
            }
        }

        // Links into the Support door only for people who can open that part of it.
        $ticketLinks = $user->canOpenSubDoor(Door::Support, 'tickets');
        $deviceLinks = $user->canOpenSubDoor(Door::Support, 'live');
        $names = [];
        $support = [];
        $entry = function (string $slug, string $raw) use (&$support, &$keys): string {
            $key = $keys[$slug] ?? 'sup-'.$slug;
            // slug: the restaurant as the Support door knows it, for loading its board card.
            $support[$key] ??= ['slug' => $raw, 'tickets' => [], 'devices' => [], 'openTickets' => 0];

            return $key;
        };

        SupportTicket::query()
            ->visibleTo($user)
            ->whereNotNull('restaurant')
            ->latest('updated_at')
            ->limit(2000)
            ->get(['id', 'public_id', 'restaurant', 'restaurant_name', 'summary', 'status', 'updated_at'])
            ->each(function (SupportTicket $ticket) use ($entry, &$support, &$names, $ticketLinks): void {
                $slug = mb_strtolower(trim((string) $ticket->restaurant));
                if ($slug === '') {
                    return;
                }
                $key = $entry($slug, trim((string) $ticket->restaurant));
                $names[$slug] ??= (string) ($ticket->restaurant_name ?: $ticket->restaurant);
                if ($ticket->status !== SupportTicketStatus::Closed) {
                    $support[$key]['openTickets']++;
                }
                if (count($support[$key]['tickets']) >= self::TICKETS_PER_RESTAURANT) {
                    return;
                }
                $support[$key]['tickets'][] = [
                    'id' => (string) $ticket->public_id,
                    'title' => (string) $ticket->summary,
                    'status' => $ticket->status?->value ?? '',
                    'statusLabel' => $ticket->status?->label() ?? '',
                    'at' => $ticket->updated_at?->toIso8601String(),
                    'url' => $ticketLinks ? route('kaman-support.inbox', ['ticket' => $ticket->public_id]) : null,
                ];
            });

        SupportDevice::query()
            ->visibleTo($user)
            ->whereNotNull('restaurant')
            ->orderByDesc('last_seen_at')
            ->get()
            ->each(function (SupportDevice $device) use ($entry, &$support, &$names, $deviceLinks): void {
                $slug = mb_strtolower(trim((string) $device->restaurant));
                if ($slug === '') {
                    return;
                }
                $key = $entry($slug, trim((string) $device->restaurant));
                $names[$slug] ??= $device->displayRestaurant();
                $support[$key]['devices'][] = [
                    'name' => (string) ($device->hostname ?: $device->device_id),
                    'cashier' => (string) $device->cashier_name,
                    'kind' => $device->deviceKind(),
                    'version' => (string) $device->app_version,
                    'product' => $device->productKey(),
                    'online' => $device->isLive(),
                    'old' => $device->isOld(),
                    'lastSeen' => $device->last_seen_at?->toIso8601String(),
                    'url' => $deviceLinks ? route('kaman-support.device', $device) : null,
                ];
            });

        $extra = [];
        foreach ($names as $slug => $name) {
            if (isset($keys[$slug])) {
                continue;
            }
            $extra[] = [
                'id' => 'sup-'.$slug,
                'name' => ['en' => $name, 'he' => $name, 'ar' => $name, 'logo' => ''],
                'owner' => '', 'phone' => '', 'cityId' => '', 'address' => ['en' => '', 'he' => '', 'ar' => ''],
                'site' => '', 'agentId' => '', 'status' => 'active', 'onboardedAt' => null, 'notes' => '',
            ];
        }

        return $this->built = ['support' => $support, 'extra' => $extra, 'keys' => $keys];
    }
}
