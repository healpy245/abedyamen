<?php

declare(strict_types=1);

namespace App\Models\OfficeVision;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    protected $table = 'officevision_devices';

    protected $fillable = [
        'name',
        'role',
        'token_hash',
        'stream_base',
        'stream_user',
        'stream_password',
        'turn_url',
        'turn_username',
        'turn_credential',
        'cameras',
        'last_seen_at',
    ];

    protected function casts(): array
    {
        return [
            'cameras' => 'array',
            'last_seen_at' => 'datetime',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(Event::class, 'device_id');
    }
}
