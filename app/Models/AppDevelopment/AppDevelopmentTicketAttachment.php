<?php

declare(strict_types=1);

namespace App\Models\AppDevelopment;

use App\Models\User;
use App\Support\AppDevelopment\FileSize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppDevelopmentTicketAttachment extends Model
{
    protected $table = 'app_development_ticket_attachments';

    protected $fillable = [
        'ticket_id',
        'uploaded_by',
        'original_name',
        'disk',
        'path',
        'mime_type',
        'size',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(AppDevelopmentTicket::class, 'ticket_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function isImage(): bool
    {
        $mime = (string) $this->mime_type;

        return str_starts_with($mime, 'image/');
    }

    public function isVideo(): bool
    {
        $mime = (string) $this->mime_type;

        return str_starts_with($mime, 'video/');
    }

    public function isAudio(): bool
    {
        $mime = strtolower((string) $this->mime_type);
        $ext = $this->extension();
        $name = strtolower((string) $this->original_name);

        if (str_starts_with($mime, 'audio/')) {
            return true;
        }

        // Browser MediaRecorder often labels voice clips as video/webm.
        if (
            str_starts_with($name, 'voice-')
            && in_array($ext, ['webm', 'ogg', 'mp3', 'm4a', 'wav', 'aac'], true)
        ) {
            return true;
        }

        if ($this->isVideo() || $this->isImage()) {
            return false;
        }

        return in_array($ext, ['ogg', 'mp3', 'm4a', 'wav', 'aac', 'webm'], true);
    }

    public function humanSize(): string
    {
        return FileSize::format((int) $this->size);
    }

    public function extension(): string
    {
        return strtolower(pathinfo((string) $this->original_name, PATHINFO_EXTENSION));
    }
}
