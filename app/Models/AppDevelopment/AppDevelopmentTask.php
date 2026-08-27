<?php

declare(strict_types=1);

namespace App\Models\AppDevelopment;

use App\Enums\AppDevelopmentTaskStatus;
use App\Enums\AppDevelopmentTicketPriority;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class AppDevelopmentTask extends Model
{
    protected $table = 'app_development_tasks';

    protected $fillable = [
        'ticket_id',
        'title',
        'description',
        'assignee_id',
        'priority',
        'status',
        'due_at',
        'created_by',
        'completed_at',
        'completion_note',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'priority' => AppDevelopmentTicketPriority::class,
            'status' => AppDevelopmentTaskStatus::class,
            'due_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(AppDevelopmentTicket::class, 'ticket_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assignee_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function timeEntries(): HasMany
    {
        return $this->hasMany(AppDevelopmentTimeEntry::class, 'task_id')->orderByDesc('started_at');
    }

    public function isCompleted(): bool
    {
        return $this->status === AppDevelopmentTaskStatus::Completed;
    }

    public function isOverdue(): bool
    {
        if ($this->due_at === null || $this->isCompleted()) {
            return false;
        }

        return $this->due_at->isPast();
    }

    public function totalDurationSeconds(): int
    {
        $closed = (int) $this->timeEntries()
            ->whereNotNull('ended_at')
            ->sum('duration_seconds');

        $open = $this->timeEntries()
            ->whereNull('ended_at')
            ->get(['started_at']);

        $running = $open->sum(static function (AppDevelopmentTimeEntry $entry): int {
            $started = $entry->started_at instanceof Carbon
                ? $entry->started_at
                : Carbon::parse((string) $entry->started_at);

            return (int) max(0, $started->diffInSeconds(Carbon::now()));
        });

        return $closed + (int) $running;
    }

    public function activeEntryFor(User $user): ?AppDevelopmentTimeEntry
    {
        return $this->timeEntries()
            ->where('user_id', $user->id)
            ->whereNull('ended_at')
            ->latest('started_at')
            ->first();
    }
}
