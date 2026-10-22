<?php

declare(strict_types=1);

namespace App\Models\KamanSupport;

use App\Enums\KamanSupport\SupportTicketStatus;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportTicket extends Model
{
    protected $table = 'support_tickets';

    protected $fillable = [
        'public_id',
        'restaurant',
        'restaurant_name',
        'cashier_name',
        'device_id',
        'locale',
        'summary',
        'details',
        'conversation',
        'diagnosis',
        'printers',
        'extra',
        'status',
        'assignee',
        'client_created_at',
        'closed_at',
        'closed_by',
        'close_note',
        'escalated_ticket_id',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'conversation' => 'array',
            'diagnosis' => 'array',
            'printers' => 'array',
            'extra' => 'array',
            'status' => SupportTicketStatus::class,
            'client_created_at' => 'datetime',
            'closed_at' => 'datetime',
        ];
    }

    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function closeDurationLabel(): ?string
    {
        if ($this->closed_at === null || $this->created_at === null) {
            return null;
        }

        $minutes = (int) floor($this->created_at->diffInSeconds($this->closed_at) / 60);
        if ($minutes < 60) {
            return $minutes.'m';
        }

        $hours = intdiv($minutes, 60);
        $rest = $minutes % 60;
        if ($hours < 48) {
            return $hours.'h '.$rest.'m';
        }

        return intdiv($hours, 24).'d '.($hours % 24).'h';
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function resolveRouteBinding($value, $field = null): ?Model
    {
        $field ??= $this->getRouteKeyName();

        return $this->where($field, $value)->orderByDesc('id')->first();
    }

    /**
     * @return HasMany<SupportTicketEvent, $this>
     */
    public function events(): HasMany
    {
        return $this->hasMany(SupportTicketEvent::class)->orderBy('id');
    }

    /**
     * @return HasOne<SupportDevice, $this>
     */
    public function device(): HasOne
    {
        return $this->hasOne(SupportDevice::class, 'device_id', 'device_id')
            ->whereColumn('support_devices.restaurant', 'support_tickets.restaurant');
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        $like = '%'.addcslashes($term, '%_\\').'%';

        return $query->where(function (Builder $inner) use ($like): void {
            $inner->where('summary', 'like', $like)
                ->orWhere('details', 'like', $like)
                ->orWhere('public_id', 'like', $like)
                ->orWhere('restaurant', 'like', $like)
                ->orWhere('restaurant_name', 'like', $like)
                ->orWhere('cashier_name', 'like', $like);
        });
    }
}
