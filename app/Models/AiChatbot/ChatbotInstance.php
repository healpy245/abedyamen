<?php

namespace App\Models\AiChatbot;

use App\Models\User;
use Database\Factories\ChatbotInstanceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class ChatbotInstance extends Model
{
    use HasFactory;

    protected $table = 'ai_chatbot_instances';

    protected $fillable = [
        'user_id',
        'name',
        'system_prompt',
        'stores_members',
        'greenapi_url',
        'greenapi_webhook_token',
        'integration_type',
        'integration_settings',
        'is_active',
        'disabled_message',
        'prompt_sections',
        'settings_schema_version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'stores_members' => 'boolean',
            'integration_settings' => 'array',
            'is_active' => 'boolean',
            'prompt_sections' => 'array',
            'settings_schema_version' => 'integer',
        ];
    }

    public function hasMalanIntegration(): bool
    {
        $type = strtolower(trim((string) ($this->integration_type ?? '')));

        return $type === (string) config('malan.integration_type', 'malan');
    }

    public function hasIntegration(string $integration): bool
    {
        return strtolower(trim((string) ($this->integration_type ?? ''))) === strtolower(trim($integration));
    }

    public function isBotGloballyActive(): bool
    {
        return (bool) ($this->is_active ?? true);
    }

    /**
     * When non-empty, Green API auto-replies only to these phone numbers.
     * Accepts local (05…) or international (972…) forms.
     *
     * @return list<string>
     */
    public function allowedReplyPhones(): array
    {
        $settings = $this->integration_settings ?? [];
        $raw = $settings['allowed_reply_phones'] ?? [];

        if (is_string($raw)) {
            $raw = preg_split('/[\s,;]+/', $raw) ?: [];
        }

        if (! is_array($raw)) {
            return [];
        }

        $phones = [];
        foreach ($raw as $entry) {
            if (! is_string($entry) && ! is_numeric($entry)) {
                continue;
            }
            $phone = trim((string) $entry);
            if ($phone !== '') {
                $phones[] = $phone;
            }
        }

        return array_values(array_unique($phones));
    }

    public function hasReplyPhoneAllowlist(): bool
    {
        return $this->allowedReplyPhones() !== [];
    }

    protected static function booted(): void
    {
        static::creating(function (ChatbotInstance $instance): void {
            if (! is_string($instance->greenapi_webhook_token) || $instance->greenapi_webhook_token === '') {
                $instance->greenapi_webhook_token = Str::random(48);
            }
        });
    }

    public function storesMembers(): bool
    {
        return (bool) $this->stores_members;
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function conversations(): HasMany
    {
        return $this->hasMany(ChatbotConversation::class, 'instance_id');
    }

    public function members(): HasMany
    {
        return $this->hasMany(ChatbotMember::class, 'instance_id');
    }

    public function instanceUsers(): HasMany
    {
        return $this->hasMany(ChatbotInstanceUser::class, 'instance_id');
    }

    public function authorizedUsers(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'ai_chatbot_instance_user', 'instance_id', 'user_id')
            ->withPivot(['role', 'permissions'])
            ->withTimestamps();
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(ChatbotAuditLog::class, 'instance_id');
    }

    protected static function newFactory(): ChatbotInstanceFactory
    {
        return ChatbotInstanceFactory::new();
    }
}
