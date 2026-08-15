<?php

declare(strict_types=1);

namespace App\Models\Voice;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCallEvent extends Model
{
    protected $fillable = [
        'voice_call_id',
        'event_type',
        'metric_key',
        'occurred_at',
        'payload',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'payload' => 'array',
        ];
    }

    public function voiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class);
    }
}
