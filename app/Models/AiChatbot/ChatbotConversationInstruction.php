<?php

namespace App\Models\AiChatbot;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotConversationInstruction extends Model
{
    protected $table = 'ai_chatbot_conversation_instructions';

    public const SCOPE_NEXT_REPLY = 'next_reply';

    public const SCOPE_PERSISTENT = 'persistent';

    public const SCOPE_REPLY_COUNT = 'reply_count';

    public const SCOPE_UNTIL_TIME = 'until_time';

    protected $fillable = [
        'conversation_id',
        'created_by',
        'instruction',
        'scope',
        'remaining_uses',
        'priority',
        'starts_at',
        'expires_at',
        'is_active',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'remaining_uses' => 'integer',
            'priority' => 'integer',
            'starts_at' => 'datetime',
            'expires_at' => 'datetime',
            'is_active' => 'boolean',
            'metadata' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isCurrentlyActive(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        $now = now();

        if ($this->starts_at !== null && $this->starts_at->isFuture()) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        if ($this->scope === self::SCOPE_REPLY_COUNT && (int) $this->remaining_uses <= 0) {
            return false;
        }

        return true;
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeActiveForGeneration(Builder $query): Builder
    {
        $now = now();

        return $query
            ->where('is_active', true)
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now);
            })
            ->where(function (Builder $q) use ($now): void {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', $now);
            })
            ->where(function (Builder $q): void {
                $q->where('scope', '!=', self::SCOPE_REPLY_COUNT)
                    ->orWhere('remaining_uses', '>', 0);
            })
            ->orderByDesc('priority')
            ->orderBy('id');
    }
}
