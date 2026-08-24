<?php

declare(strict_types=1);

namespace App\Services\AppDevelopment;

use App\Enums\AppDevelopmentTicketActivityType;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\AppDevelopment\AppDevelopmentTicketAttachment;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class TicketAttachmentService
{
    public function __construct(
        private readonly TicketWorkflowService $workflow,
    ) {}

    public function store(AppDevelopmentTicket $ticket, User $user, UploadedFile $file): AppDevelopmentTicketAttachment
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $storedName = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        $path = $file->storeAs(
            'app-development/tickets/'.$ticket->id.'/attachments',
            $storedName,
            'local',
        );

        if ($path === false || ! Storage::disk('local')->exists($path)) {
            throw new \RuntimeException(__('app-development.errors.attachment_store_failed'));
        }

        $attachment = AppDevelopmentTicketAttachment::query()->create([
            'ticket_id' => $ticket->id,
            'uploaded_by' => $user->id,
            'original_name' => $file->getClientOriginalName(),
            'disk' => 'local',
            'path' => $path,
            'mime_type' => $file->getMimeType() ?: $file->getClientMimeType(),
            'size' => $file->getSize() ?: Storage::disk('local')->size($path),
        ]);

        $this->workflow->record(
            $ticket,
            $user,
            AppDevelopmentTicketActivityType::AttachmentAdded,
            $ticket->status,
            $ticket->status,
            [
                'attachment_id' => $attachment->id,
                'original_name' => $attachment->original_name,
            ],
        );

        return $attachment;
    }

    public function download(AppDevelopmentTicketAttachment $attachment): StreamedResponse
    {
        $disk = Storage::disk($attachment->disk ?: 'local');

        if (! $disk->exists($attachment->path)) {
            abort(404);
        }

        $inline = $attachment->isImage() || $attachment->isVideo();

        $filename = str_replace(["\"", "\r", "\n"], '', (string) $attachment->original_name);

        return $disk->response(
            $attachment->path,
            $inline ? null : $filename,
            [
                'Content-Type' => (string) ($attachment->mime_type ?: 'application/octet-stream'),
                'Cache-Control' => 'private, max-age=3600',
                'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.$filename.'"',
            ],
        );
    }
}
