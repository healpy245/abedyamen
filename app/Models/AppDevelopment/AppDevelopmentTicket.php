<?php

declare(strict_types=1);

namespace App\Models\AppDevelopment;

use App\Enums\AppDevelopmentAppType;
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
        'priority_changed_by',
        'status_changed_by',
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

    public function priorityChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'priority_changed_by');
    }

    public function statusChangedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'status_changed_by');
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

    public function appTypeRows(): HasMany
    {
        return $this->hasMany(AppDevelopmentTicketAppType::class, 'ticket_id');
    }

    /**
     * @return list<AppDevelopmentAppType>
     */
    public function appTypes(): array
    {
        return $this->appTypeRows
            ->map(static fn (AppDevelopmentTicketAppType $row): AppDevelopmentAppType => $row->app_type)
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  list<string|AppDevelopmentAppType>  $types
     */
    public function syncAppTypes(array $types): void
    {
        $values = collect($types)
            ->map(static function (string|AppDevelopmentAppType $type): ?string {
                if ($type instanceof AppDevelopmentAppType) {
                    return $type->value;
                }

                return AppDevelopmentAppType::tryFrom($type)?->value;
            })
            ->filter()
            ->unique()
            ->values()
            ->all();

        $this->appTypeRows()->delete();

        foreach ($values as $value) {
            $this->appTypeRows()->create(['app_type' => $value]);
        }

        $this->unsetRelation('appTypeRows');
    }

    /**
     * @return array<string, int>
     */
    public static function appTypeCountsMap(): array
    {
        $raw = AppDevelopmentTicketAppType::query()
            ->toBase()
            ->selectRaw('app_type, count(*) as aggregate')
            ->groupBy('app_type')
            ->pluck('aggregate', 'app_type');

        $counts = [];
        foreach (AppDevelopmentAppType::cases() as $type) {
            $counts[$type->value] = (int) ($raw[$type->value] ?? 0);
        }

        return $counts;
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

    /**
     * @return array<string, int>
     */
    public static function statusCountsMap(): array
    {
        $raw = static::query()
            ->toBase()
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        $counts = [];
        foreach (AppDevelopmentTicketStatus::cases() as $status) {
            $counts[$status->value] = (int) ($raw[$status->value] ?? 0);
        }

        return $counts;
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
