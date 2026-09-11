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
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

class TicketAttachmentService
{
    public function __construct(
        private readonly TicketWorkflowService $workflow,
    ) {}

    public function store(AppDevelopmentTicket $ticket, User $user, UploadedFile $file): AppDevelopmentTicketAttachment
    {
        return $this->storeFile($ticket, $user, $file, recordActivity: true);
    }

    public function storeForComment(AppDevelopmentTicket $ticket, User $user, UploadedFile $file): AppDevelopmentTicketAttachment
    {
        return $this->storeFile($ticket, $user, $file, recordActivity: false);
    }

    private function storeFile(
        AppDevelopmentTicket $ticket,
        User $user,
        UploadedFile $file,
        bool $recordActivity,
    ): AppDevelopmentTicketAttachment {
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

        if ($recordActivity) {
            $this->workflow->record(
                $ticket,
                $user,
                AppDevelopmentTicketActivityType::AttachmentAdded,
                $ticket->status,
                $ticket->status,
                [
                    'attachment_id' => $attachment->id,
                    'original_name' => $attachment->original_name,
                    'excerpt' => $attachment->original_name,
                ],
            );
        }

        return $attachment;
    }

    /**
     * Serve media with HTTP Range support so browsers can start video/audio
     * playback before the full file finishes downloading.
     */
    public function download(AppDevelopmentTicketAttachment $attachment): BinaryFileResponse
    {
        $disk = Storage::disk($attachment->disk ?: 'local');

        if (! $disk->exists($attachment->path)) {
            abort(404);
        }

        $absolute = $disk->path($attachment->path);
        $inline = $attachment->isImage() || $attachment->isVideo() || $attachment->isAudio();
        $filename = str_replace(["\"", "\r", "\n"], '', (string) $attachment->original_name);
        $mime = (string) ($attachment->mime_type ?: 'application/octet-stream');

        if ($attachment->isVideo() && ! str_starts_with($mime, 'video/')) {
            $mime = 'video/mp4';
        }

        $response = new BinaryFileResponse($absolute, 200, [
            'Content-Type' => $mime,
            'Cache-Control' => 'private, max-age=86400',
            'Accept-Ranges' => 'bytes',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        $response->setContentDisposition(
            $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $filename,
        );
        $response->headers->set(
            'Content-Disposition',
            ($inline ? 'inline' : 'attachment').'; filename="'.$filename.'"',
        );

        return $response;
    }

    public function deleteForTicket(AppDevelopmentTicket $ticket): void
    {
        $attachments = $ticket->attachments()->get();

        foreach ($attachments as $attachment) {
            $disk = Storage::disk($attachment->disk ?: 'local');
            if ($attachment->path && $disk->exists($attachment->path)) {
                $disk->delete($attachment->path);
            }
            $attachment->delete();
        }

        Storage::disk('local')->deleteDirectory('app-development/tickets/'.$ticket->id);
    }
}
