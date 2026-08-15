<?php

namespace App\Models\Voice;

use App\Enums\Voice\VoiceCallMessageRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VoiceCallMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'voice_call_id',
        'role',
        'content',
        'provider_event_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => VoiceCallMessageRole::class,
            'metadata' => 'array',
        ];
    }

    public function voiceCall(): BelongsTo
    {
        return $this->belongsTo(VoiceCall::class);
    }
}
