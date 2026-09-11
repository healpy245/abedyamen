<?php

declare(strict_types=1);

namespace App\Models\AppDevelopment;

use App\Enums\AppDevelopmentRole;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AppDevelopmentMember extends Model
{
    protected $table = 'app_development_members';

    protected $fillable = [
        'user_id',
        'role',
        'phone',
        'whatsapp_notifications_enabled',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => AppDevelopmentRole::class,
            'whatsapp_notifications_enabled' => 'boolean',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isQa(): bool
    {
        return $this->role === AppDevelopmentRole::Qa
            || $this->role === AppDevelopmentRole::Admin;
    }

    public function isDeveloper(): bool
    {
        return $this->role === AppDevelopmentRole::Developer
            || $this->role === AppDevelopmentRole::Admin;
    }

    public function wantsWhatsappNotifications(): bool
    {
        return (bool) $this->whatsapp_notifications_enabled
            && filled($this->phone);
    }

    /**
     * @return list<int>
     */
    public static function assignableDeveloperUserIds(): array
    {
        return static::query()
            ->whereIn('role', [AppDevelopmentRole::Developer, AppDevelopmentRole::Admin])
            ->pluck('user_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * @return Builder<static>
     */
    public static function selectableDevelopers(): Builder
    {
        return static::query()
            ->with('user:id,name,email')
            ->whereIn('role', [AppDevelopmentRole::Developer, AppDevelopmentRole::Admin])
            ->whereNotNull('phone')
            ->where('phone', '!=', '');
    }

    /**
     * @return Builder<static>
     */
    public static function selectableTesters(): Builder
    {
        return static::query()
            ->with('user:id,name,email')
            ->whereIn('role', [AppDevelopmentRole::Qa, AppDevelopmentRole::Admin])
            ->whereNotNull('phone')
            ->where('phone', '!=', '');
    }

    /**
     * @return Builder<static>
     */
    public static function notifiableDevelopers(): Builder
    {
        return static::selectableDevelopers()
            ->where('whatsapp_notifications_enabled', true);
    }

    /**
     * @return Builder<static>
     */
    public static function notifiableTesters(): Builder
    {
        return static::selectableTesters()
            ->where('whatsapp_notifications_enabled', true);
    }

    /**
     * @return list<string>
     */
    public static function allWorkerPhones(): array
    {
        return static::query()
            ->whereNotNull('phone')
            ->where('phone', '!=', '')
            ->pluck('phone')
            ->map(fn ($phone): string => (string) $phone)
            ->unique()
            ->values()
            ->all();
    }
}
