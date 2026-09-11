<?php

namespace App\Models\AiChatbot;

use App\Models\User;
use App\Support\InternetCompanyProfile;
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
        'bot_activated_at',
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
            'bot_activated_at' => 'datetime',
            'prompt_sections' => 'array',
            'settings_schema_version' => 'integer',
        ];
    }

    public function hasMalanIntegration(): bool
    {
        $type = strtolower(trim((string) ($this->integration_type ?? '')));

        return in_array($type, [
            (string) config('malan.integration_type', 'malan'),
            InternetCompanyProfile::SLUG_SPEEDCOM,
        ], true);
    }

    public function companyProfile(): InternetCompanyProfile
    {
        return InternetCompanyProfile::forInstance($this);
    }

    public function campaignDefaultCity(): string
    {
        return $this->companyProfile()->campaignDefaultCity;
    }

    public function hasKamanWhatsappIntegration(): bool
    {
        return $this->hasIntegration('kaman_whatsapp');
    }

    public function hasIntegration(string $integration): bool
    {
        return strtolower(trim((string) ($this->integration_type ?? ''))) === strtolower(trim($integration));
    }

    /**
     * Copy shown on the multi-bot picker (same workspace cards as the home tools).
     */
    public function workspaceDescription(): string
    {
        if ($this->companyProfile()->isSpeedcom()) {
            return __('chatbot.choose.speedcom_description');
        }

        if ($this->hasMalanIntegration()) {
            return __('chatbot.choose.malan_description');
        }

        if ($this->hasKamanWhatsappIntegration()) {
            return __('chatbot.choose.kaman_description');
        }

        return __('chatbot.choose.generic_description');
    }

    public function workspaceIcon(): string
    {
        if ($this->hasMalanIntegration()) {
            return 'sparkles';
        }

        if ($this->hasKamanWhatsappIntegration()) {
            return 'whatsapp';
        }

        return 'chat-bubble-left-right';
    }

    /**
     * Light accent classes for picker cards (same shape as Project::tone()).
     *
     * @return array{
     *     icon_bg: string,
     *     icon_text: string,
     *     border_hover: string,
     *     status_bg: string,
     *     status_text: string,
     *     status_border: string
     * }
     */
    public function workspaceTone(): array
    {
        if ($this->companyProfile()->isSpeedcom()) {
            return [
                'icon_bg' => 'bg-teal-50',
                'icon_text' => 'text-teal-600',
                'border_hover' => 'hover:border-teal-200',
                'status_bg' => 'bg-emerald-50',
                'status_text' => 'text-emerald-700',
                'status_border' => 'border-emerald-200',
            ];
        }

        if ($this->hasMalanIntegration()) {
            return [
                'icon_bg' => 'bg-violet-50',
                'icon_text' => 'text-violet-600',
                'border_hover' => 'hover:border-violet-200',
                'status_bg' => 'bg-emerald-50',
                'status_text' => 'text-emerald-700',
                'status_border' => 'border-emerald-200',
            ];
        }

        if ($this->hasKamanWhatsappIntegration()) {
            return [
                'icon_bg' => 'bg-sky-50',
                'icon_text' => 'text-sky-600',
                'border_hover' => 'hover:border-sky-200',
                'status_bg' => 'bg-emerald-50',
                'status_text' => 'text-emerald-700',
                'status_border' => 'border-emerald-200',
            ];
        }

        return [
            'icon_bg' => 'bg-orange-50',
            'icon_text' => 'text-orange-600',
            'border_hover' => 'hover:border-orange-200',
            'status_bg' => 'bg-emerald-50',
            'status_text' => 'text-emerald-700',
            'status_border' => 'border-emerald-200',
        ];
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
        return $this->phoneListFromSettings('allowed_reply_phones');
    }

    public function hasReplyPhoneAllowlist(): bool
    {
        return $this->allowedReplyPhones() !== [];
    }

    /**
     * Numbers the bot must never auto-reply to, even when globally active.
     * Accepts local (05…) or international (972…) forms.
     *
     * @return list<string>
     */
    public function ignoredReplyPhones(): array
    {
        return $this->phoneListFromSettings('ignored_reply_phones');
    }

    public function hasReplyPhoneIgnoreList(): bool
    {
        return $this->ignoredReplyPhones() !== [];
    }

    /**
     * Parse a textarea / JSON list of phones into a clean unique list.
     *
     * @return list<string>
     */
    public static function parsePhoneList(?string $raw): array
    {
        $raw = trim((string) $raw);
        if ($raw === '') {
            return [];
        }

        $lines = preg_split('/\R+/', $raw) ?: [];
        $phones = [];
        foreach ($lines as $line) {
            $phone = trim((string) $line);
            if ($phone !== '') {
                $phones[] = $phone;
            }
        }

        return array_values(array_unique($phones));
    }

    /**
     * @return list<string>
     */
    private function phoneListFromSettings(string $key): array
    {
        $settings = $this->integration_settings ?? [];
        $raw = $settings[$key] ?? [];

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

    /**
     * Delete all conversations for this instance (messages/contexts cascade).
     * Keeps the instance, members, prompts, and integration settings.
     *
     * @return array{conversations:int}
     */
    public function clearAllConversations(): array
    {
        $count = (int) $this->conversations()->count();
        // Mass delete relies on DB cascade for messages/contexts/instructions/etc.
        $this->conversations()->delete();

        return ['conversations' => $count];
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
