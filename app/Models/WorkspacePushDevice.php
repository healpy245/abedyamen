<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One phone running the Kaman Workspace app that can take a push.
 *
 * @property string $token
 * @property string $locale
 * @property bool $notify_tickets
 * @property bool $notify_failures
 */
class WorkspacePushDevice extends Model
{
    protected $table = 'workspace_push_devices';

    protected $fillable = [
        'user_id',
        'token',
        'token_hash',
        'platform',
        'app_version',
        'locale',
        'notify_tickets',
        'notify_failures',
        'last_seen_at',
        'last_sent_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notify_tickets' => 'boolean',
            'notify_failures' => 'boolean',
            'last_seen_at' => 'datetime',
            'last_sent_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Tokens are long, so the unique index sits on their hash.
     */
    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }
}
