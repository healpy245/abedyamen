<?php

declare(strict_types=1);

namespace App\Services;

use App\Enums\AppDevelopmentTicketStatus;
use App\Enums\KamanSupport\SupportTicketStatus;
use App\Enums\Project;
use App\Models\AppDevelopment\AppDevelopmentRelease;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\KamanSupport\SupportDevice;
use App\Models\KamanSupport\SupportTicket;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The short list a Kaman worker sees after login.
 */
class TodayService
{
    /**
     * @return array{
     *     items: list<array{label: string, title: string, detail: string, href: string}>,
     *     doors: list<Project>
     * }
     */
    public function for(User $user): array
    {
        return [
            'items' => $this->items($user),
            'doors' => $this->doors($user),
        ];
    }

    /**
     * @return list<array{label: string, title: string, detail: string, href: string}>
     */
    private function items(User $user): array
    {
        $items = [];

        if ($user->canAccessProject(Project::KamanSupport)) {
            $items = array_merge($items, $this->liveDevices($user));
            if (! $user->limitedSupportConsole()) {
                $items = array_merge($items, $this->supportTickets());
            }
        }

        if ($user->canAccessProject(Project::AppDevelopment)) {
            if ($user->isQa()) {
                $items = array_merge($items, $this->releaseToCheck(), $this->qaTickets());
            }
            if ($user->isDeveloper()) {
                $items = array_merge($items, $this->assignedTickets($user));
            }
        }

        return $items;
    }

    /**
     * @return list<Project>
     */
    private function doors(User $user): array
    {
        return array_values(array_filter(
            $user->accessibleProjects(),
            fn (Project $project): bool => $project !== Project::AiChatbot,
        ));
    }

    /**
     * @return list<array{label: string, title: string, detail: string, href: string}>
     */
    private function liveDevices(User $user): array
    {
        $window = max(15, (int) config('kaman-support.live_seen_seconds', 90));
        $query = SupportDevice::query()
            ->where('last_seen_at', '>=', now()->subSeconds($window))
            ->where(function ($query): void {
                $query->whereNull('app_state')->orWhere('app_state', '!=', 'closed');
            });

        $prefix = $user->posAppVersionPrefix();
        if ($prefix !== null) {
            $query->where('app_version', 'like', $prefix.'%');
        }

        $count = $query->count();
        if ($count === 0) {
            return [];
        }

        return [[
            'label' => __('app.today_live_label'),
            'title' => trans_choice('app.today_live', $count, ['count' => $count]),
            'detail' => __('app.today_live_detail'),
            'href' => route('kaman-support.live'),
        ]];
    }

    /**
     * @return list<array{label: string, title: string, detail: string, href: string}>
     */
    private function supportTickets(): array
    {
        return SupportTicket::query()
            ->whereIn('status', [SupportTicketStatus::Open, SupportTicketStatus::InProgress])
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->map(function (SupportTicket $ticket): array {
                $name = trim((string) ($ticket->restaurant_name ?: $ticket->restaurant));

                return [
                    'label' => __('app.today_support_ticket'),
                    'title' => $name !== '' ? $name : $ticket->public_id,
                    'detail' => Str::limit(trim((string) $ticket->summary), 90),
                    'href' => route('kaman-support.show', $ticket),
                ];
            })
            ->all();
    }

    /**
     * @return list<array{label: string, title: string, detail: string, href: string}>
     */
    private function releaseToCheck(): array
    {
        $release = AppDevelopmentRelease::query()
            ->where('is_latest', true)
            ->where('created_at', '>=', now()->subDays(7))
            ->orderByDesc('id')
            ->first();

        if ($release === null) {
            return [];
        }

        return [[
            'label' => __('app.today_release'),
            'title' => $release->version_name,
            'detail' => Str::limit(trim((string) ($release->title ?: $release->release_notes)), 90),
            'href' => route('app-development.releases.show', $release),
        ]];
    }

    /**
     * @return list<array{label: string, title: string, detail: string, href: string}>
     */
    private function qaTickets(): array
    {
        return AppDevelopmentTicket::query()
            ->where('status', AppDevelopmentTicketStatus::Qa)
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->map(fn (AppDevelopmentTicket $ticket): array => [
                'label' => __('app.today_qa'),
                'title' => $ticket->title,
                'detail' => $ticket->ticket_number,
                'href' => route('app-development.tickets.show', $ticket),
            ])
            ->all();
    }

    /**
     * @return list<array{label: string, title: string, detail: string, href: string}>
     */
    private function assignedTickets(User $user): array
    {
        return AppDevelopmentTicket::query()
            ->where('assigned_to', $user->id)
            ->whereIn('status', [AppDevelopmentTicketStatus::Open, AppDevelopmentTicketStatus::Working])
            ->orderByDesc('id')
            ->limit(3)
            ->get()
            ->map(fn (AppDevelopmentTicket $ticket): array => [
                'label' => __('app.today_dev'),
                'title' => $ticket->title,
                'detail' => $ticket->ticket_number,
                'href' => route('app-development.tickets.show', $ticket),
            ])
            ->all();
    }
}
