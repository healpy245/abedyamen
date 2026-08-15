<?php

namespace App\Models\Voice;

use App\Enums\Voice\VoiceCallStatus;
use App\Models\AiChatbot\ChatbotConversation;
use App\Models\AiChatbot\ChatbotInstance;
use App\Models\User;
use Database\Factories\VoiceCallFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VoiceCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'chatbot_instance_id',
        'provider',
        'provider_call_id',
        'caller_number',
        'called_number',
        'status',
        'chatbot_conversation_id',
        'started_at',
        'answered_at',
        'ended_at',
        'duration_seconds',
        'failure_reason',
        'conversation_summary',
        'metadata',
        'realtime_model',
        'realtime_voice',
        'greeting_played_at',
        'interruption_count',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => VoiceCallStatus::class,
            'started_at' => 'datetime',
            'answered_at' => 'datetime',
            'ended_at' => 'datetime',
            'greeting_played_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function chatbotInstance(): BelongsTo
    {
        return $this->belongsTo(ChatbotInstance::class, 'chatbot_instance_id');
    }

    public function chatbotConversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'chatbot_conversation_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(VoiceCallMessage::class);
    }

    public function events(): HasMany
    {
        return $this->hasMany(VoiceCallEvent::class);
    }

    public function statusEnum(): VoiceCallStatus
    {
        $status = $this->status;

        return $status instanceof VoiceCallStatus
            ? $status
            : VoiceCallStatus::from((string) $status);
    }

    public function isActive(): bool
    {
        return $this->statusEnum()->acceptsMessages();
    }

    public function isTerminal(): bool
    {
        return $this->statusEnum()->isTerminal();
    }

    public function hasProcessedEvent(string $eventId): bool
    {
        $processed = $this->metadata['processed_event_ids'] ?? [];

        return is_array($processed) && in_array($eventId, $processed, true);
    }

    public function markEventProcessed(string $eventId): void
    {
        $metadata = $this->metadata ?? [];
        $processed = $metadata['processed_event_ids'] ?? [];

        if (! is_array($processed)) {
            $processed = [];
        }

        if (! in_array($eventId, $processed, true)) {
            $processed[] = $eventId;
        }

        $metadata['processed_event_ids'] = $processed;
        $this->metadata = $metadata;
        $this->save();
    }

    protected static function newFactory(): VoiceCallFactory
    {
        return VoiceCallFactory::new();
    }
}
