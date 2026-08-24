<?php

declare(strict_types=1);

namespace App\Models\AppDevelopment;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppDevelopmentReleaseDownload extends Model
{
    public $timestamps = false;

    protected $table = 'app_development_release_downloads';

    protected $fillable = [
        'release_id',
        'user_id',
        'downloaded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'downloaded_at' => 'datetime',
        ];
    }

    public function release(): BelongsTo
    {
        return $this->belongsTo(AppDevelopmentRelease::class, 'release_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
