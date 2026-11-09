<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * The Kaman WhatsApp line used to send finance, sales, and app-development notices.
 * The row still lives in ai_chatbot_instances, which is where the send URL was saved.
 */
class KamanWhatsappLine extends Model
{
    protected $table = 'ai_chatbot_instances';

    protected $fillable = [
        'user_id',
        'name',
        'system_prompt',
        'stores_members',
        'integration_type',
        'greenapi_url',
        'greenapi_webhook_token',
        'integration_settings',
    ];

    protected function casts(): array
    {
        return [
            'stores_members' => 'boolean',
            'integration_settings' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $line): void {
            if (! is_string($line->greenapi_webhook_token) || $line->greenapi_webhook_token === '') {
                $line->greenapi_webhook_token = Str::random(48);
            }
        });
    }

    public static function kaman(): ?self
    {
        return self::query()
            ->where('integration_type', 'kaman_whatsapp')
            ->orderBy('id')
            ->first();
    }

    /**
     * @return list<string>
     */
    public function manualIgnoredReplyPhones(): array
    {
        return $this->phoneList('ignored_reply_phones');
    }

    /**
     * @return list<string>
     */
    public function apiIgnoredReplyPhones(): array
    {
        return $this->phoneList('ignored_reply_phones_api');
    }

    /**
     * @return list<string>
     */
    public function ignoredReplyPhones(): array
    {
        return array_values(array_unique(array_merge(
            $this->manualIgnoredReplyPhones(),
            $this->apiIgnoredReplyPhones(),
        )));
    }

    /**
     * @return list<string>
     */
    private function phoneList(string $key): array
    {
        $settings = is_array($this->integration_settings) ? $this->integration_settings : [];
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
}
