<?php

declare(strict_types=1);

namespace App\Http\Controllers\AppDevelopment;

use App\Http\Controllers\Controller;
use App\Http\Requests\AppDevelopment\StoreAttachmentRequest;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\AppDevelopment\AppDevelopmentTicketAttachment;
use App\Services\AppDevelopment\TicketAttachmentService;
use Illuminate\Http\RedirectResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TicketAttachmentController extends Controller
{
    public function __construct(
        private readonly TicketAttachmentService $attachments,
    ) {}

    public function store(StoreAttachmentRequest $request, AppDevelopmentTicket $ticket): RedirectResponse
    {
        foreach ($request->uploadedFiles() as $file) {
            $this->attachments->store($ticket, $request->user(), $file);
        }

        return redirect()
            ->route('app-development.tickets.show', $ticket)
            ->with('success', __('app-development.flash.attachment_added'));
    }

    public function download(AppDevelopmentTicket $ticket, AppDevelopmentTicketAttachment $attachment): BinaryFileResponse
    {
        $this->authorize('downloadAttachment', $ticket);

        if ((int) $attachment->ticket_id !== (int) $ticket->id) {
            abort(404);
        }

        return $this->attachments->download($attachment);
    }
}
