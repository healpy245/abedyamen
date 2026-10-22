<?php

declare(strict_types=1);

namespace App\Models\AiChatbot;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MansourSendRun extends Model
{
    protected $table = 'mansour_send_runs';

    protected $fillable = [
        'instance_id',
        'sent',
        'failed',
        'note',
        'results',
        'finished_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'sent' => 'integer',
            'failed' => 'integer',
            'results' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function instance(): BelongsTo
    {
        return $this->belongsTo(ChatbotInstance::class, 'instance_id');
    }
}
