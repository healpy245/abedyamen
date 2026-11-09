<?php

declare(strict_types=1);

namespace App\Models\KamanSupport;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class SupportDevice extends Model
{
    public const OLD_AFTER_DAYS = 3;

    protected $table = 'support_devices';

    protected $fillable = [
        'restaurant',
        'restaurant_name',
        'device_id',
        'cashier_name',
        'cashier_email',
        'role',
        'platform',
        'app_version',
        'product',
        'hostname',
        'locale',
        'session_id',
        'app_state',
        'logged_in',
        'opened_at',
        'closed_at',
        'log_count',
        'extra',
        'last_ticket_id',
        'last_seen_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'logged_in' => 'boolean',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'extra' => 'array',
        ];
    }

    /**
     * @return HasMany<SupportDeviceLog, $this>
     */
    public function logs(): HasMany
    {
        return $this->hasMany(SupportDeviceLog::class)->orderByDesc('id');
    }

    /**
     * @return HasMany<SupportDeviceCommand, $this>
     */
    public function commands(): HasMany
    {
        return $this->hasMany(SupportDeviceCommand::class)->orderByDesc('id');
    }

    /**
     * Not connected and not seen for this long, so a restaurant's list leaves it out until asked.
     */
    public function isOld(): bool
    {
        return ! $this->isLive()
            && ($this->last_seen_at === null || $this->last_seen_at->lt(now()->subDays(self::OLD_AFTER_DAYS)));
    }

    public function liveWindowSeconds(): int
    {
        return max(15, (int) config('kaman-support.live_seen_seconds', 90));
    }

    public function isLive(?int $seconds = null): bool
    {
        if ($this->app_state === 'closed') {
            return false;
        }

        $seen = $this->last_seen_at;
        $window = $seconds ?? $this->liveWindowSeconds();

        return $seen instanceof Carbon && $seen->greaterThan(now()->subSeconds(max(15, $window)));
    }

    public function isRecentlySeen(int $minutes = 2): bool
    {
        $seen = $this->last_seen_at;
        $window = max(15, $minutes * 60);

        return $seen instanceof Carbon && $seen->greaterThan(now()->subSeconds($window));
    }

    public function displayRestaurant(): string
    {
        $name = trim((string) $this->restaurant_name);

        return $name !== '' ? $name : (string) $this->restaurant;
    }

    public function deviceKind(): string
    {
        $extra = is_array($this->extra) ? $this->extra : [];
        $kind = strtolower(trim((string) ($extra['deviceKind'] ?? $extra['device_kind'] ?? '')));
        if (in_array($kind, ['tablet', 'desktop', 'web', 'phone'], true)) {
            return $kind;
        }

        $platform = strtolower(trim((string) $this->platform));

        return match (true) {
            in_array($platform, ['android', 'ios', 'tablet'], true) => 'tablet',
            $platform === 'web' => 'web',
            default => 'desktop',
        };
    }

    /**
     * Which app this is: 'mall' (Kaman Cashier Mall) or 'lite' (Kaman Lite), as the app reports it.
     * Builds that do not report it yet: 1.3.x is only ever Lite. 1.0.x is used by both apps, so it
     * stays unknown rather than guessed.
     */
    public function productKey(): string
    {
        $product = (string) $this->product;
        if ($product !== '') {
            return $product;
        }

        return preg_match('/^v?1\.3(?:\.|$)/i', trim((string) $this->app_version)) === 1 ? 'lite' : '';
    }

    /**
     * Kaman Cashier devices: those reporting it, and 1.0.x builds that do not report a product yet.
     * Kaman Lite on 1.0.x cannot be told apart until that build reports its product.
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeKamanCashier(Builder $query): Builder
    {
        return $query->where(function (Builder $inner): void {
            $inner->where('product', 'mall')->orWhere(function (Builder $unknown): void {
                $unknown->whereNull('product')->where(function (Builder $version): void {
                    $version->where('app_version', 'like', '1.0.%')->orWhere('app_version', 'like', 'v1.0.%');
                });
            });
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeVisibleTo(Builder $query, ?User $user): Builder
    {
        return $user?->supportProduct() === 'mall' ? $query->kamanCashier() : $query;
    }

    public function isVisibleTo(User $user): bool
    {
        return static::query()->whereKey($this->getKey())->visibleTo($user)->exists();
    }

    public function isTablet(): bool
    {
        return $this->deviceKind() === 'tablet';
    }
}
