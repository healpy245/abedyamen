<?php

declare(strict_types=1);

namespace App\Services\AppDevelopment;

use App\Enums\AppDevelopmentTicketActivityType;
use App\Enums\AppDevelopmentTicketStatus;
use App\Exceptions\AppDevelopment\TicketWorkflowException;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\AppDevelopment\AppDevelopmentTicketActivity;
use App\Models\AppDevelopment\AppDevelopmentTicketComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class TicketWorkflowService
{
    public function startWork(AppDevelopmentTicket $ticket, User $user): AppDevelopmentTicket
    {
        $this->assertDeveloper($user);

        return DB::transaction(function () use ($ticket, $user): AppDevelopmentTicket {
            $locked = $this->lock($ticket);

            if ($locked->status !== AppDevelopmentTicketStatus::Open) {
                if ($locked->assigned_to !== null && (int) $locked->assigned_to !== (int) $user->id) {
                    throw TicketWorkflowException::conflict(__('app-development.errors.already_assigned'));
                }

                throw TicketWorkflowException::invalid(__('app-development.errors.cannot_start_work'));
            }

            $from = $locked->status;
            $previousAssignee = $locked->assigned_to;

            $locked->forceFill([
                'status' => AppDevelopmentTicketStatus::Working,
                'assigned_to' => $user->id,
            ])->save();

            $this->record(
                $locked,
                $user,
                AppDevelopmentTicketActivityType::StartedWork,
                $from,
                AppDevelopmentTicketStatus::Working,
                [
                    'assigned_from' => $previousAssignee,
                    'assigned_to' => $user->id,
                ],
            );

            return $locked->refresh();
        });
    }

    public function assignDeveloper(AppDevelopmentTicket $ticket, User $actor, User $developer): AppDevelopmentTicket
    {
        $this->assertDeveloper($actor);

        if (! $developer->isDeveloper()) {
            throw TicketWorkflowException::invalid(__('app-development.errors.assignee_must_be_developer'));
        }

        return DB::transaction(function () use ($ticket, $actor, $developer): AppDevelopmentTicket {
            $locked = $this->lock($ticket);

            if ($locked->isCompleted()) {
                throw TicketWorkflowException::invalid(__('app-development.errors.completed_is_terminal'));
            }

            $from = $locked->status;
            $previous = $locked->assigned_to;

            if ((int) $previous === (int) $developer->id) {
                return $locked;
            }

            $locked->forceFill(['assigned_to' => $developer->id])->save();

            $this->record(
                $locked,
                $actor,
                AppDevelopmentTicketActivityType::Assigned,
                $from,
                $from,
                [
                    'assigned_from' => $previous,
                    'assigned_to' => $developer->id,
                ],
            );

            return $locked->refresh();
        });
    }

    public function submitForQa(AppDevelopmentTicket $ticket, User $user, ?string $note = null): AppDevelopmentTicket
    {
        $this->assertDeveloper($user);

        $note = $this->normalizeNote($note);

        return DB::transaction(function () use ($ticket, $user, $note): AppDevelopmentTicket {
            $locked = $this->lock($ticket);

            if ($locked->status !== AppDevelopmentTicketStatus::Working) {
                throw TicketWorkflowException::invalid(__('app-development.errors.cannot_send_to_qa'));
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => AppDevelopmentTicketStatus::Qa,
                'submitted_for_qa_at' => now(),
            ])->save();

            if ($note !== null) {
                $this->addNoteComment($locked, $user, $note);
            }

            $this->record(
                $locked,
                $user,
                AppDevelopmentTicketActivityType::SentToQa,
                $from,
                AppDevelopmentTicketStatus::Qa,
                ['note' => $note],
            );

            return $locked->refresh();
        });
    }

    public function returnToDevelopment(AppDevelopmentTicket $ticket, User $user, string $note): AppDevelopmentTicket
    {
        $this->assertQa($user);

        $note = $this->normalizeNote($note);

        if ($note === null) {
            throw TicketWorkflowException::invalid(__('app-development.errors.rejection_note_required'));
        }

        return DB::transaction(function () use ($ticket, $user, $note): AppDevelopmentTicket {
            $locked = $this->lock($ticket);

            if ($locked->status !== AppDevelopmentTicketStatus::Qa) {
                throw TicketWorkflowException::invalid(__('app-development.errors.cannot_return_to_developer'));
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => AppDevelopmentTicketStatus::Working,
                'qa_rejection_count' => $locked->qa_rejection_count + 1,
            ])->save();

            $this->addNoteComment($locked, $user, $note);

            $this->record(
                $locked,
                $user,
                AppDevelopmentTicketActivityType::QaRejected,
                $from,
                AppDevelopmentTicketStatus::Working,
                [
                    'note' => $note,
                    'assigned_to' => $locked->assigned_to,
                ],
            );

            return $locked->refresh();
        });
    }

    public function complete(AppDevelopmentTicket $ticket, User $user, ?string $note = null): AppDevelopmentTicket
    {
        $this->assertQa($user);

        $note = $this->normalizeNote($note);

        return DB::transaction(function () use ($ticket, $user, $note): AppDevelopmentTicket {
            $locked = $this->lock($ticket);

            if ($locked->status !== AppDevelopmentTicketStatus::Qa) {
                throw TicketWorkflowException::invalid(__('app-development.errors.cannot_complete'));
            }

            $from = $locked->status;

            $locked->forceFill([
                'status' => AppDevelopmentTicketStatus::Completed,
                'completed_at' => now(),
                'completed_by' => $user->id,
            ])->save();

            if ($note !== null) {
                $this->addNoteComment($locked, $user, $note);
            }

            $this->record(
                $locked,
                $user,
                AppDevelopmentTicketActivityType::Completed,
                $from,
                AppDevelopmentTicketStatus::Completed,
                ['note' => $note],
            );

            return $locked->refresh();
        });
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        AppDevelopmentTicket $ticket,
        ?User $user,
        AppDevelopmentTicketActivityType $type,
        ?AppDevelopmentTicketStatus $from,
        ?AppDevelopmentTicketStatus $to,
        array $metadata = [],
    ): AppDevelopmentTicketActivity {
        return AppDevelopmentTicketActivity::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user?->id,
            'event_type' => $type,
            'from_status' => $from,
            'to_status' => $to,
            'metadata' => $metadata === [] ? null : $metadata,
            'created_at' => now(),
        ]);
    }

    private function lock(AppDevelopmentTicket $ticket): AppDevelopmentTicket
    {
        return AppDevelopmentTicket::query()
            ->whereKey($ticket->id)
            ->lockForUpdate()
            ->firstOrFail();
    }

    private function assertDeveloper(User $user): void
    {
        if (! $user->isDeveloper()) {
            throw TicketWorkflowException::denied(__('app-development.errors.developer_only'));
        }
    }

    private function assertQa(User $user): void
    {
        if (! $user->isQa()) {
            throw TicketWorkflowException::denied(__('app-development.errors.qa_only'));
        }
    }

    private function normalizeNote(?string $note): ?string
    {
        $note = trim((string) $note);

        return $note === '' ? null : $note;
    }

    private function addNoteComment(AppDevelopmentTicket $ticket, User $user, string $note): void
    {
        AppDevelopmentTicketComment::query()->create([
            'ticket_id' => $ticket->id,
            'user_id' => $user->id,
            'body' => $note,
        ]);
    }
}
