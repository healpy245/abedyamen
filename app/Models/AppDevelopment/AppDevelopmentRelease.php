<?php

declare(strict_types=1);

namespace App\Models\AppDevelopment;

use App\Models\User;
use App\Support\AppDevelopment\FileSize;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AppDevelopmentRelease extends Model
{
    protected $table = 'app_development_releases';

    protected $fillable = [
        'version_name',
        'version_code',
        'title',
        'release_notes',
        'disk',
        'file_path',
        'original_filename',
        'file_size',
        'checksum_sha256',
        'uploaded_by',
        'is_latest',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'version_code' => 'integer',
            'file_size' => 'integer',
            'is_latest' => 'boolean',
        ];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function tickets(): BelongsToMany
    {
        return $this->belongsToMany(
            AppDevelopmentTicket::class,
            'app_development_release_ticket',
            'release_id',
            'ticket_id',
        )->withTimestamps();
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(AppDevelopmentReleaseDownload::class, 'release_id')->orderByDesc('downloaded_at');
    }

    public function humanSize(): string
    {
        return FileSize::format((int) $this->file_size);
    }
}
