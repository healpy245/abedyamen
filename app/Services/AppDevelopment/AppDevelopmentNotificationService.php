<?php

declare(strict_types=1);

namespace App\Services\AppDevelopment;

use App\Enums\AppDevelopmentRole;
use App\Enums\AppDevelopmentTicketActivityType;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentRelease;
use App\Models\AppDevelopment\AppDevelopmentTask;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\AppDevelopment\AppDevelopmentTicketComment;
use App\Models\User;
use App\Notifications\AppDevelopmentEvent;
use App\Notifications\AppDevelopmentReleaseUploaded;
use App\Notifications\AppDevelopmentTaskAssigned;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;
use Throwable;

class AppDevelopmentNotificationService
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function notifyActivity(
        AppDevelopmentTicket $ticket,
        ?User $actor,
        AppDevelopmentTicketActivityType $type,
        array $metadata = [],
    ): void {
        $ids = $this->recipientIds($ticket, $actor, $type, $metadata);

        if ($ids === []) {
            return;
        }

        $users = User::query()->whereIn('id', $ids)->get();

        if ($users->isEmpty()) {
            return;
        }

        Notification::send($users, new AppDevelopmentEvent($type, $ticket, $actor, $metadata));
    }

    public function notifyRelease(AppDevelopmentRelease $release, User $actor): void
    {
        $ids = $this->memberIds(AppDevelopmentRole::Qa);
        $ids = array_values(array_filter($ids, fn (int $id): bool => $id !== (int) $actor->id));

        if ($ids === []) {
            return;
        }

        Notification::send(
            User::query()->whereIn('id', $ids)->get(),
            new AppDevelopmentReleaseUploaded($release, $actor),
        );
    }

    public function notifyTaskAssigned(AppDevelopmentTask $task, User $actor): void
    {
        $assigneeId = (int) ($task->assignee_id ?? 0);
        if ($assigneeId <= 0 || $assigneeId === (int) $actor->id) {
            return;
        }

        $assignee = User::query()->find($assigneeId);
        if ($assignee === null) {
            return;
        }

        $task->loadMissing('ticket');
        $assignee->notify(new AppDevelopmentTaskAssigned($task, $actor));

        try {
            $this->whatsappTaskAssigned($task, $assignee);
        } catch (Throwable $e) {
            Log::warning('App Development task WhatsApp notify failed', [
                'task_id' => $task->id,
                'user_id' => $assignee->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function whatsappTaskAssigned(AppDevelopmentTask $task, User $assignee): void
    {
        $member = AppDevelopmentMember::query()
            ->where('user_id', $assignee->id)
            ->first();

        if ($member === null || ! $member->wantsWhatsappNotifications()) {
            return;
        }

        $whatsapp = app(AppDevelopmentWhatsAppNotifyService::class);
        $instance = $whatsapp->kamanWhatsappInstance();
        if ($instance === null) {
            return;
        }

        $sendUrl = trim((string) ($instance->greenapi_url ?? ''));
        if ($sendUrl === '') {
            return;
        }

        $chatId = $whatsapp->chatIdFromPhone((string) $member->phone);
        if ($chatId === '') {
            return;
        }

        $ticket = $task->ticket;
        $message = __('app-development.whatsapp.task_assigned', [
            'name' => $assignee->name,
            'task' => $task->title,
            'ticket' => $ticket?->ticket_number ?? '',
        ]);

        $greenApi = app(\App\Services\AiChatbot\ChatbotGreenApiService::class);
        $greenApi->sendMessage($sendUrl, $chatId, $message, 0);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<int>
     */
    private function recipientIds(
        AppDevelopmentTicket $ticket,
        ?User $actor,
        AppDevelopmentTicketActivityType $type,
        array $metadata,
    ): array {
        $qa = $this->memberIds(AppDevelopmentRole::Qa);
        $developers = $this->memberIds(AppDevelopmentRole::Developer);
        $participants = $this->participantIds($ticket);
        $assignee = (int) ($metadata['assigned_to'] ?? $ticket->assigned_to);

        $ids = match ($type) {
            AppDevelopmentTicketActivityType::Created => $ticket->assigned_to
                ? $qa
                : array_merge($qa, $developers),
            AppDevelopmentTicketActivityType::Updated => $participants,
            AppDevelopmentTicketActivityType::Assigned => $assignee > 0 ? [$assignee] : [],
            AppDevelopmentTicketActivityType::StartedWork => [(int) $ticket->created_by],
            AppDevelopmentTicketActivityType::Commented => $this->commentNotifyIds($ticket, $metadata),
            AppDevelopmentTicketActivityType::AttachmentAdded => array_merge($participants, $this->commenterIds($ticket)),
            AppDevelopmentTicketActivityType::SentToQa => $qa,
            AppDevelopmentTicketActivityType::QaRejected => $assignee > 0 ? [$assignee] : [],
            AppDevelopmentTicketActivityType::Completed => array_merge($participants, $qa),
            AppDevelopmentTicketActivityType::ApkLinked => array_merge($qa, $participants),
        };

        return $this->exceptActor($ids, $actor);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return list<int>
     */
    private function commentNotifyIds(AppDevelopmentTicket $ticket, array $metadata): array
    {
        $selected = $metadata['notify_user_ids'] ?? null;
        if (is_array($selected) && $selected !== []) {
            return array_values(array_unique(array_map(
                static fn ($id): int => (int) $id,
                $selected,
            )));
        }

        return array_merge($this->participantIds($ticket), $this->commenterIds($ticket));
    }

    /**
     * @return list<int>
     */
    private function participantIds(AppDevelopmentTicket $ticket): array
    {
        return array_values(array_filter([
            (int) $ticket->created_by,
            $ticket->assigned_to ? (int) $ticket->assigned_to : 0,
        ]));
    }

    /**
     * @return list<int>
     */
    private function commenterIds(AppDevelopmentTicket $ticket): array
    {
        return AppDevelopmentTicketComment::query()
            ->where('ticket_id', $ticket->id)
            ->distinct()
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return list<int>
     */
    private function memberIds(AppDevelopmentRole $role): array
    {
        $roles = match ($role) {
            AppDevelopmentRole::Qa => [AppDevelopmentRole::Qa, AppDevelopmentRole::Admin],
            AppDevelopmentRole::Developer => [AppDevelopmentRole::Developer, AppDevelopmentRole::Admin],
            AppDevelopmentRole::Admin => [AppDevelopmentRole::Admin],
        };

        return AppDevelopmentMember::query()
            ->whereIn('role', $roles)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $ids
     * @return list<int>
     */
    private function exceptActor(array $ids, ?User $actor): array
    {
        $actorId = $actor?->id;

        $allowed = AppDevelopmentMember::query()
            ->whereIn('user_id', $ids)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique(array_filter(
            $allowed,
            fn (int $id): bool => $id > 0 && $id !== (int) $actorId,
        )));
    }
}
