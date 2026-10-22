<?php

declare(strict_types=1);

namespace App\Models\OfficeVision;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class ApiToken extends Model
{
    protected $table = 'officevision_api_tokens';

    protected $fillable = [
        'token_hash',
        'kind',
        'user_id',
        'device_id',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public static function issue(string $kind, ?int $userId, ?int $deviceId): string
    {
        $plain = 'ov_'.Str::random(48);
        static::query()->create([
            'token_hash' => hash('sha256', $plain),
            'kind' => $kind,
            'user_id' => $userId,
            'device_id' => $deviceId,
        ]);

        return $plain;
    }

    public static function findPlain(string $plain): ?self
    {
        return static::query()->where('token_hash', hash('sha256', $plain))->first();
    }
}
