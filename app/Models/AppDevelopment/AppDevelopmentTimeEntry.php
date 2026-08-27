<?php

declare(strict_types=1);

namespace App\Models\AppDevelopment;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class AppDevelopmentTimeEntry extends Model
{
    protected $table = 'app_development_time_entries';

    protected $fillable = [
        'task_id',
        'user_id',
        'started_at',
        'ended_at',
        'duration_seconds',
        'note',
        'edited_by',
        'edit_reason',
        'original_duration_seconds',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
            'original_duration_seconds' => 'integer',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(AppDevelopmentTask::class, 'task_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function editedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'edited_by');
    }

    public function isActive(): bool
    {
        return $this->ended_at === null;
    }

    public function elapsedSeconds(?Carbon $at = null): int
    {
        if ($this->ended_at !== null && $this->duration_seconds !== null) {
            return (int) $this->duration_seconds;
        }

        $started = $this->started_at instanceof Carbon
            ? $this->started_at
            : Carbon::parse((string) $this->started_at);

        return (int) max(0, $started->diffInSeconds($at ?? Carbon::now()));
    }
}
