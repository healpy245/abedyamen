<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FormWorkflowRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_PAUSED = 'paused';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'user_id',
        'method_type',
        'subdomain',
        'environment',
        'status',
        'payload',
        'result',
        'events',
        'error',
        'started_at',
        'paused_at',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'result' => 'array',
            'events' => 'array',
            'started_at' => 'datetime',
            'paused_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isRunning(): bool
    {
        return $this->status === self::STATUS_RUNNING;
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function canContinue(): bool
    {
        if ($this->isPaused()) {
            return true;
        }

        if ($this->status !== self::STATUS_FAILED) {
            return false;
        }

        $result = is_array($this->result) ? $this->result : [];

        return (bool) ($result['needs_login'] ?? false);
    }
}
