<?php

declare(strict_types=1);

namespace App\Models\KamanSupport;

use App\Enums\KamanSupport\SupportTicketStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
        ];
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
