<?php

declare(strict_types=1);

namespace App\Models\OfficeVision;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Camera extends Model
{
    protected $table = 'officevision_cameras';

    protected $fillable = [
        'device_id',
        'name',
        'path',
        'online',
        'persons',
        'sort',
    ];

    protected function casts(): array
    {
        return [
            'online' => 'boolean',
        ];
    }

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }
}
