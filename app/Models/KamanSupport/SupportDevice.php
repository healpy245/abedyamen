<?php

declare(strict_types=1);

namespace App\Models\KamanSupport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

class SupportDevice extends Model
{
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

    public function isTablet(): bool
    {
        return $this->deviceKind() === 'tablet';
    }
}
