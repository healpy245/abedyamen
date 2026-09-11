<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Enums\AppDevelopmentTicketActivityType;
use App\Http\Controllers\Controller;
use App\Http\Requests\AppDevelopment\StoreCommentRequest;
use App\Models\AppDevelopment\AppDevelopmentMember;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\AppDevelopment\AppDevelopmentTicketComment;
use App\Services\AppDevelopment\TicketAttachmentService;
use App\Services\AppDevelopment\TicketWorkflowService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class TicketCommentController extends Controller
{
    public function __construct(
        private readonly TicketWorkflowService $workflow,
        private readonly TicketAttachmentService $attachments,
    ) {}

    public function store(StoreCommentRequest $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        $body = trim((string) ($request->validated('body') ?? ''));
        $voices = $request->file('voices', []) ?: [];
        $voiceFiles = array_values(array_filter(
            is_array($voices) ? $voices : [$voices],
            static fn ($file): bool => $file instanceof UploadedFile,
        ));

        $notifyIds = array_values(array_unique(array_map(
            static fn ($id): int => (int) $id,
            $request->validated('notify_user_ids') ?? [],
        )));

        $memberIds = AppDevelopmentMember::query()
            ->whereIn('user_id', $notifyIds === [] ? [0] : $notifyIds)
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        DB::transaction(function () use ($request, $ticket, $body, $voiceFiles, $memberIds): void {
            $user = $request->user();

            if ($voiceFiles !== []) {
                foreach ($voiceFiles as $index => $file) {
                    $attachment = $this->attachments->storeForComment($ticket, $user, $file);
                    $commentBody = ($index === 0 && $body !== '')
                        ? $body
                        : __('app-development.tickets.voice_note');

                    $comment = AppDevelopmentTicketComment::query()->create([
                        'ticket_id' => $ticket->id,
                        'user_id' => $user->id,
                        'body' => $commentBody,
                        'attachment_id' => $attachment->id,
                    ]);

                    $this->workflow->record(
                        $ticket,
                        $user,
                        AppDevelopmentTicketActivityType::Commented,
                        $ticket->status,
                        $ticket->status,
                        [
                            'comment_id' => $comment->id,
                            'note' => $comment->body,
                            'attachment_id' => $attachment->id,
                            'notify_user_ids' => $index === 0 ? $memberIds : [],
                        ],
                    );
                }

                return;
            }

            if ($body !== '') {
                $comment = AppDevelopmentTicketComment::query()->create([
                    'ticket_id' => $ticket->id,
                    'user_id' => $user->id,
                    'body' => $body,
                ]);

                $this->workflow->record(
                    $ticket,
                    $user,
                    AppDevelopmentTicketActivityType::Commented,
                    $ticket->status,
                    $ticket->status,
                    [
                        'comment_id' => $comment->id,
                        'note' => $comment->body,
                        'notify_user_ids' => $memberIds,
                    ],
                );
            }
        });

        return redirect()
            ->route('app-development.tickets.show', $ticket)
            ->with('success', __('app-development.flash.comment_added'));
    }
}
