<?php

declare(strict_types=1);

namespace App\Models\AppDevelopment;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppDevelopmentStagedUpload extends Model
{
    protected $table = 'app_development_staged_uploads';

    protected $fillable = [
        'user_id',
        'uuid',
        'original_name',
        'extension',
        'mime_type',
        'total_size',
        'received_size',
        'chunk_size',
        'total_chunks',
        'received_chunks',
        'disk',
        'path',
        'kind',
        'status',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'total_size' => 'integer',
            'received_size' => 'integer',
            'chunk_size' => 'integer',
            'total_chunks' => 'integer',
            'received_chunks' => 'integer',
            'expires_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isReady(): bool
    {
        return $this->status === 'ready';
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
