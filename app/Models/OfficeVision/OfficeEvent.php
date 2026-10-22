<?php

declare(strict_types=1);

namespace App\Models\OfficeVision;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OfficeEvent extends Model
{
    protected $table = 'officevision_events';

    protected $fillable = [
        'device_id',
        'uid',
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
            'occurred_at' => 'datetime',
            'confidence' => 'float',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
