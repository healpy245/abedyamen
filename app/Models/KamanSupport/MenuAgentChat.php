<?php

declare(strict_types=1);

namespace App\Models\KamanSupport;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One AI Menu conversation, kept so the support team can reopen it later.
 *
 * @property list<array{role: string, content: string}> $messages
 */
class MenuAgentChat extends Model
{
    protected $fillable = [
        'user_id',
        'subdomain',
        'title',
        'messages',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'messages' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
