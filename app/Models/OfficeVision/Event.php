<?php

declare(strict_types=1);

namespace App\Models\OfficeVision;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Event extends Model
{
    protected $table = 'officevision_events';

    protected $fillable = [
        'device_id',
        'event_uid',
        'camera_name',
        'event_type',
        'worker_name',
        'confidence',
        'occurred_at',
        'clip_path',
    ];

    protected function casts(): array
    {
        return [
            'confidence' => 'float',
            'occurred_at' => 'datetime',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}
