<?php

declare(strict_types=1);

namespace App\Services\AppDevelopment;

use App\Models\AppDevelopment\AppDevelopmentStagedUpload;
use App\Models\AppDevelopment\AppDevelopmentTicket;
use App\Models\AppDevelopment\AppDevelopmentTicketAttachment;
use App\Models\User;
use App\Support\AppDevelopment\TicketMedia;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class TicketStagingUploadService
{
    public const CHUNK_BYTES = 1024 * 1024; // 1 MB — short requests, hard to timeout

    public const TTL_HOURS = 24;

    public function __construct(
        private readonly TicketWorkflowService $workflow,
    ) {}

    /**
     * @return array{upload: AppDevelopmentStagedUpload, chunk_size: int}
     */
    public function init(
        User $user,
        string $originalName,
        int $totalSize,
        ?string $mimeType,
        string $kind = 'attachment',
    ): array {
        if ($totalSize <= 0) {
            throw new InvalidArgumentException(__('app-development.errors.upload_empty'));
        }

        $maxBytes = TicketMedia::MAX_KILOBYTES * 1024;
        if ($totalSize > $maxBytes) {
            throw new InvalidArgumentException(__('validation.max.file', [
                'attribute' => 'file',
                'max' => TicketMedia::MAX_KILOBYTES,
            ]));
        }

        $extension = strtolower((string) pathinfo($originalName, PATHINFO_EXTENSION));
        if ($extension === '' || ! in_array($extension, TicketMedia::EXTENSIONS, true)) {
            throw new InvalidArgumentException(__('validation.extensions', [
                'attribute' => 'file',
                'values' => implode(', ', TicketMedia::EXTENSIONS),
            ]));
        }

        $kind = $kind === 'voice' ? 'voice' : 'attachment';
        $uuid = (string) Str::uuid();
        $storedName = $uuid.($extension !== '' ? '.'.$extension : '');
        $path = 'app-development/staging/'.$user->id.'/'.$storedName;

        Storage::disk('local')->makeDirectory('app-development/staging/'.$user->id);
        Storage::disk('local')->put($path, '');

        $chunkSize = self::CHUNK_BYTES;
        $totalChunks = (int) max(1, (int) ceil($totalSize / $chunkSize));

        $upload = AppDevelopmentStagedUpload::query()->create([
            'user_id' => $user->id,
            'uuid' => $uuid,
            'original_name' => $originalName,
            'extension' => $extension,
            'mime_type' => $mimeType,
            'total_size' => $totalSize,
            'received_size' => 0,
            'chunk_size' => $chunkSize,
            'total_chunks' => $totalChunks,
            'received_chunks' => 0,
            'disk' => 'local',
            'path' => $path,
            'kind' => $kind,
            'status' => 'pending',
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]);

        return [
            'upload' => $upload,
            'chunk_size' => $chunkSize,
        ];
    }

    public function appendChunk(
        AppDevelopmentStagedUpload $upload,
        User $user,
        int $index,
        UploadedFile $chunk,
    ): AppDevelopmentStagedUpload {
        $this->assertOwnedPending($upload, $user);

        if ($index < 0 || $index >= $upload->total_chunks) {
            throw new InvalidArgumentException(__('app-development.errors.upload_chunk_invalid'));
        }

        if ($index !== $upload->received_chunks) {
            // Idempotent retry of the current expected chunk.
            if ($index < $upload->received_chunks) {
                return $upload;
            }

            throw new InvalidArgumentException(__('app-development.errors.upload_chunk_order'));
        }

        $absolute = Storage::disk($upload->disk)->path($upload->path);
        $handle = fopen($absolute, 'ab');
        if ($handle === false) {
            throw new RuntimeException(__('app-development.errors.attachment_store_failed'));
        }

        $source = fopen($chunk->getRealPath(), 'rb');
        if ($source === false) {
            fclose($handle);
            throw new RuntimeException(__('app-development.errors.attachment_store_failed'));
        }

        stream_copy_to_stream($source, $handle);
        fclose($source);
        fclose($handle);

        $received = (int) filesize($absolute);
        $upload->forceFill([
            'received_size' => $received,
            'received_chunks' => $upload->received_chunks + 1,
        ])->save();

        if ($upload->received_chunks >= $upload->total_chunks) {
            if ($received !== (int) $upload->total_size) {
                $upload->forceFill(['status' => 'failed'])->save();
                throw new RuntimeException(__('app-development.errors.upload_size_mismatch'));
            }

            $upload->forceFill([
                'status' => 'ready',
                'mime_type' => $upload->mime_type ?: (mime_content_type($absolute) ?: null),
            ])->save();
        }

        return $upload->refresh();
    }

    /**
     * @param  list<string>  $uuids
     * @return list<AppDevelopmentTicketAttachment>
     */
    public function claimMany(AppDevelopmentTicket $ticket, User $user, array $uuids): array
    {
        $claimed = [];

        foreach ($uuids as $uuid) {
            $uuid = trim((string) $uuid);
            if ($uuid === '') {
                continue;
            }

            $staged = AppDevelopmentStagedUpload::query()
                ->where('uuid', $uuid)
                ->where('user_id', $user->id)
                ->first();

            if ($staged === null || ! $staged->isReady() || $staged->isExpired()) {
                throw new RuntimeException(__('app-development.errors.upload_not_ready'));
            }

            $claimed[] = $this->claimOne($ticket, $user, $staged);
        }

        return $claimed;
    }

    public function claimOne(
        AppDevelopmentTicket $ticket,
        User $user,
        AppDevelopmentStagedUpload $staged,
    ): AppDevelopmentTicketAttachment {
        $this->assertOwned($staged, $user);

        if (! $staged->isReady()) {
            throw new RuntimeException(__('app-development.errors.upload_not_ready'));
        }

        $disk = Storage::disk($staged->disk);
        if (! $disk->exists($staged->path)) {
            throw new RuntimeException(__('app-development.errors.attachment_store_failed'));
        }

        $extension = (string) ($staged->extension ?: '');
        $storedName = Str::uuid()->toString().($extension !== '' ? '.'.$extension : '');
        $finalPath = 'app-development/tickets/'.$ticket->id.'/attachments/'.$storedName;

        $moved = $disk->move($staged->path, $finalPath);
        if (! $moved) {
            throw new RuntimeException(__('app-development.errors.attachment_store_failed'));
        }

        $attachment = AppDevelopmentTicketAttachment::query()->create([
            'ticket_id' => $ticket->id,
            'uploaded_by' => $user->id,
            'original_name' => $staged->original_name,
            'disk' => $staged->disk,
            'path' => $finalPath,
            'mime_type' => $staged->mime_type ?: 'application/octet-stream',
            'size' => $staged->total_size,
        ]);

        $staged->forceFill(['status' => 'claimed'])->save();

        $this->workflow->record(
            $ticket,
            $user,
            \App\Enums\AppDevelopmentTicketActivityType::AttachmentAdded,
            $ticket->status,
            $ticket->status,
            [
                'attachment_id' => $attachment->id,
                'original_name' => $attachment->original_name,
                'excerpt' => $attachment->original_name,
            ],
        );

        return $attachment;
    }

    public function purgeExpired(): int
    {
        $expired = AppDevelopmentStagedUpload::query()
            ->where('expires_at', '<', now())
            ->whereIn('status', ['pending', 'ready', 'failed'])
            ->get();

        $count = 0;
        foreach ($expired as $upload) {
            $disk = Storage::disk($upload->disk);
            if ($upload->path && $disk->exists($upload->path)) {
                $disk->delete($upload->path);
            }
            $upload->delete();
            $count++;
        }

        return $count;
    }

    private function assertOwnedPending(AppDevelopmentStagedUpload $upload, User $user): void
    {
        $this->assertOwned($upload, $user);

        if ($upload->status !== 'pending' || $upload->isExpired()) {
            throw new InvalidArgumentException(__('app-development.errors.upload_not_ready'));
        }
    }

    private function assertOwned(AppDevelopmentStagedUpload $upload, User $user): void
    {
        if ((int) $upload->user_id !== (int) $user->id) {
            abort(403);
        }
    }
}
