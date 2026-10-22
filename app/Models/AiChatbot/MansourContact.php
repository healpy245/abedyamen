<?php

declare(strict_types=1);

namespace App\Models\AiChatbot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MansourContact extends Model
{
    protected $table = 'mansour_contacts';

    protected $fillable = [
        'instance_id',
        'name',
        'phone',
        'phone_normalized',
        'chat_id',
        'conversation_id',
        'triggered_at',
        'followup_queued_at',
        'triggered_lines',
        'last_error',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'triggered_at' => 'datetime',
            'followup_queued_at' => 'datetime',
            'triggered_lines' => 'array',
        ];
    }

    /**
     * True when this contact was triggered on that WhatsApp line.
     * Older rows have one timestamp and no map: they count only for the line
     * stored on their chat, or for the primary line when the chat has no line.
     */
    public function sentOnLine(string $lineId, ?string $primaryId): bool
    {
        $map = is_array($this->triggered_lines) ? $this->triggered_lines : [];
        if ($map !== []) {
            foreach ($map as $key => $value) {
                if ((string) $key === $lineId && $value) {
                    return true;
                }
            }

            return false;
        }
        if ($this->triggered_at === null) {
            return false;
        }

        $stored = trim((string) data_get($this->conversation?->metadata, 'mansour_line', ''));
        if ($stored !== '') {
            return $stored === $lineId;
        }

        return $primaryId !== null && $primaryId !== '' && $lineId === $primaryId;
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ChatbotInstance::class, 'instance_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id');
    }
}
