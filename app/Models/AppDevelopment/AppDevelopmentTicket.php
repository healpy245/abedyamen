<?php

declare(strict_types=1);

namespace App\Models\AppDevelopment;

use App\Enums\AppDevelopmentTicketActivityType;
use App\Enums\AppDevelopmentTicketPriority;
use App\Enums\AppDevelopmentTicketStatus;
use App\Enums\AppDevelopmentTicketType;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class AppDevelopmentTicket extends Model
{
    protected $table = 'app_development_tickets';

    protected $fillable = [
        'ticket_number',
        'title',
        'description',
        'type',
        'priority',
        'status',
        'created_by',
        'assigned_to',
        'submitted_for_qa_at',
        'completed_at',
        'completed_by',
        'qa_rejection_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AppDevelopmentTicketType::class,
            'priority' => AppDevelopmentTicketPriority::class,
            'status' => AppDevelopmentTicketStatus::class,
            'submitted_for_qa_at' => 'datetime',
            'completed_at' => 'datetime',
            'qa_rejection_count' => 'integer',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'ticket_number';
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignedDeveloper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function completedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function comments(): HasMany
    {
        return $this->hasMany(AppDevelopmentTicketComment::class, 'ticket_id')->orderBy('created_at');
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(AppDevelopmentTicketAttachment::class, 'ticket_id')->orderBy('created_at');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(AppDevelopmentTicketActivity::class, 'ticket_id')->orderBy('created_at');
    }

    public function releases(): BelongsToMany
    {
        return $this->belongsToMany(
            AppDevelopmentRelease::class,
            'app_development_release_ticket',
            'ticket_id',
            'release_id',
        )->withTimestamps();
    }

    public function latestQaRejection(): HasOne
    {
        return $this->hasOne(AppDevelopmentTicketActivity::class, 'ticket_id')
            ->ofMany(['id' => 'max'], function (Builder $query): void {
                $query->where('event_type', AppDevelopmentTicketActivityType::QaRejected->value);
            });
    }

    public function isOpen(): bool
    {
        return $this->status === AppDevelopmentTicketStatus::Open;
    }

    public function isWorking(): bool
    {
        return $this->status === AppDevelopmentTicketStatus::Working;
    }

    public function isWaitingForQa(): bool
    {
        return $this->status === AppDevelopmentTicketStatus::Qa;
    }

    public function isCompleted(): bool
    {
        return $this->status === AppDevelopmentTicketStatus::Completed;
    }

    public function isReturnedFromQa(): bool
    {
        return $this->isWorking() && $this->qa_rejection_count > 0;
    }

    public function elapsedLabel(): string
    {
        $end = $this->completed_at ?? Carbon::now();

        return $this->created_at?->shortAbsoluteDiffForHumans($end, 2) ?? '';
    }

    public function latestRelease(): ?AppDevelopmentRelease
    {
        return $this->releases->first();
    }
}
