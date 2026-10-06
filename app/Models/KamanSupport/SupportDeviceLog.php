<?php

declare(strict_types=1);

namespace App\Models\KamanSupport;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupportDeviceLog extends Model
{
    protected $table = 'support_device_logs';

    protected $fillable = [
        'support_device_id',
        'client_id',
        'level',
        'source',
        'message',
        'context',
        'logged_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'context' => 'array',
            'logged_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<SupportDevice, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(SupportDevice::class, 'support_device_id');
    }

    /**
     * @return array{bg: string, text: string, border: string}
     */
    public function tone(): array
    {
        return match ($this->level) {
            'error' => [
                'bg' => 'bg-rose-50',
                'text' => 'text-rose-800',
                'border' => 'border-rose-200',
            ],
            'warn', 'warning' => [
                'bg' => 'bg-amber-50',
                'text' => 'text-amber-800',
                'border' => 'border-amber-200',
            ],
            'debug' => [
                'bg' => 'bg-slate-50',
                'text' => 'text-slate-600',
                'border' => 'border-slate-200',
            ],
            default => [
                'bg' => 'bg-sky-50',
                'text' => 'text-sky-800',
                'border' => 'border-sky-200',
            ],
        };
    }
}
