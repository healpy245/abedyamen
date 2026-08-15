<?php

namespace App\Models\AiChatbot;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ChatbotConversation extends Model
{
    use HasFactory;

    public const CHANNEL_WEB = 'web';

    public const CHANNEL_WHATSAPP = 'whatsapp';

    public const CHANNEL_VOICE = 'voice';

    public const CHANNEL_TEST = 'test';

    public const BOT_MODE_ACTIVE = 'active';

    public const BOT_MODE_PAUSED = 'paused';

    public const BOT_MODE_HUMAN_TAKEOVER = 'human_takeover';

    public const ATTENTION_NORMAL = 'normal';

    public const ATTENTION_NEEDS = 'needs_attention';

    public const ATTENTION_RESOLVED = 'resolved';

    protected $table = 'ai_chatbot_conversations';

    protected $fillable = [
        'user_id',
        'instance_id',
        'title',
        'channel',
        'external_chat_id',
        'contact_phone',
        'contact_name',
        'contact_avatar_url',
        'last_message_at',
        'last_customer_message_at',
        'last_assistant_message_at',
        'unread_count',
        'attention_status',
        'bot_mode',
        'assigned_user_id',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'last_message_at' => 'datetime',
            'last_customer_message_at' => 'datetime',
            'last_assistant_message_at' => 'datetime',
            'unread_count' => 'integer',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ChatbotInstance::class, 'instance_id');
    }

    public function assignedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(ChatbotMessage::class, 'conversation_id');
    }

    public function latestMessage(): HasOne
    {
        return $this->hasOne(ChatbotMessage::class, 'conversation_id')->latestOfMany();
    }

    public function malanContext(): HasOne
    {
        return $this->hasOne(ChatbotConversationContext::class, 'conversation_id');
    }

    public function toolExecutions(): HasMany
    {
        return $this->hasMany(ChatbotToolExecution::class, 'conversation_id');
    }

    public function instructions(): HasMany
    {
        return $this->hasMany(ChatbotConversationInstruction::class, 'conversation_id');
    }

    public function isWhatsApp(): bool
    {
        return $this->channel === self::CHANNEL_WHATSAPP;
    }

    public function isTest(): bool
    {
        return $this->channel === self::CHANNEL_TEST;
    }

    public function allowsAutomaticReply(): bool
    {
        return ($this->bot_mode ?? self::BOT_MODE_ACTIVE) === self::BOT_MODE_ACTIVE;
    }

    public function displayName(): string
    {
        $name = trim((string) ($this->contact_name ?? ''));
        if ($name !== '') {
            return $name;
        }

        $phone = trim((string) ($this->contact_phone ?? ''));
        if ($phone !== '') {
            return $phone;
        }

        return (string) ($this->title ?: __('chatbot.untitled_chat'));
    }

    public function initials(): string
    {
        $label = $this->displayName();
        $parts = preg_split('/\s+/u', $label) ?: [];
        $chars = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $chars .= mb_strtoupper(mb_substr($part, 0, 1));
        }

        return $chars !== '' ? $chars : '?';
    }

    public function markRead(): void
    {
        if ((int) $this->unread_count === 0) {
            return;
        }

        $this->forceFill(['unread_count' => 0])->save();
    }

    public function recordCustomerActivity(?string $preview = null): void
    {
        $now = now();
        $attrs = [
            'last_message_at' => $now,
            'last_customer_message_at' => $now,
            'unread_count' => (int) $this->unread_count + 1,
        ];

        if ($preview !== null && $preview !== '' && $this->title === null) {
            $trimmed = trim(preg_replace('/\s+/', ' ', $preview) ?? $preview);
            $attrs['title'] = mb_substr($trimmed, 0, 60) ?: 'New chat';
        }

        $this->forceFill($attrs)->save();
    }

    public function recordAssistantActivity(): void
    {
        $now = now();
        $this->forceFill([
            'last_message_at' => $now,
            'last_assistant_message_at' => $now,
        ])->save();
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeCustomerFacing(Builder $query): Builder
    {
        return $query->where('channel', '!=', self::CHANNEL_TEST);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeWhatsApp(Builder $query): Builder
    {
        return $query->where('channel', self::CHANNEL_WHATSAPP);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeForExternalChat(Builder $query, int $instanceId, string $channel, string $externalChatId): Builder
    {
        return $query
            ->where('instance_id', $instanceId)
            ->where('channel', $channel)
            ->where('external_chat_id', $externalChatId);
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeSearchContact(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term): void {
            $q->where('contact_name', 'like', '%'.$term.'%')
                ->orWhere('contact_phone', 'like', '%'.$term.'%')
                ->orWhere('title', 'like', '%'.$term.'%')
                ->orWhere('external_chat_id', 'like', '%'.$term.'%');
        });
    }

    /**
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeFilterWorkspace(Builder $query, ?string $filter): Builder
    {
        return match ($filter) {
            'unread' => $query->where('unread_count', '>', 0),
            'needs_attention' => $query->where('attention_status', self::ATTENTION_NEEDS),
            'human_takeover' => $query->where('bot_mode', self::BOT_MODE_HUMAN_TAKEOVER),
            'bot_active' => $query->where('bot_mode', self::BOT_MODE_ACTIVE),
            default => $query,
        };
    }
}
