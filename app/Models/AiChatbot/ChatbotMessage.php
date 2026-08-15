<?php

namespace App\Models\AiChatbot;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChatbotMessage extends Model
{
    use HasFactory;

    public const REPLY_SOURCE_CUSTOMER = 'customer';

    public const REPLY_SOURCE_AI = 'ai';

    public const REPLY_SOURCE_AI_INSTRUCTED = 'ai_instructed';

    public const REPLY_SOURCE_HUMAN = 'human';

    public const REPLY_SOURCE_SYSTEM = 'system';

    protected $table = 'ai_chatbot_messages';

    protected $fillable = [
        'conversation_id',
        'role',
        'sender_type',
        'external_message_id',
        'message_type',
        'sent_by_user_id',
        'reply_source',
        'delivery_status',
        'read_at',
        'metadata',
        'message',
        'attachment_disk',
        'attachment_path',
        'attachment_mime',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'read_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(ChatbotConversation::class, 'conversation_id');
    }

    public function sentByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by_user_id');
    }

    public function hasAttachment(): bool
    {
        return is_string($this->attachment_path) && $this->attachment_path !== '';
    }

    public function isImageAttachment(): bool
    {
        $mime = strtolower((string) $this->attachment_mime);

        return str_starts_with($mime, 'image/');
    }

    public function isPdfAttachment(): bool
    {
        return strtolower((string) $this->attachment_mime) === 'application/pdf'
            || str_ends_with(strtolower((string) ($this->attachment_path ?? '')), '.pdf');
    }

    public function isAudioAttachment(): bool
    {
        $mime = strtolower((string) $this->attachment_mime);
        if (str_starts_with($mime, 'audio/') || $mime === 'application/ogg') {
            return true;
        }

        $path = strtolower((string) ($this->attachment_path ?? ''));

        return (bool) preg_match('/\.(ogg|mp3|m4a|aac|opus|wav|webm|amr|3gp)$/', $path);
    }

    public function staffSourceLabel(): ?string
    {
        return match ($this->reply_source) {
            self::REPLY_SOURCE_AI => 'AI',
            self::REPLY_SOURCE_AI_INSTRUCTED => 'AI + instruction',
            self::REPLY_SOURCE_HUMAN => 'Human',
            self::REPLY_SOURCE_SYSTEM => 'System',
            default => null,
        };
    }
}
